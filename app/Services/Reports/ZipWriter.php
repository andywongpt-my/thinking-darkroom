<?php

namespace App\Services\Reports;

/**
 * Minimal deterministic ZIP writer — stored (no-compression) mode only.
 *
 * Why hand-rolled instead of ZipArchive or a composer package:
 *  - php-zip is not installed locally nor in the Vercel runtime, and adding
 *    a composer dependency requires approval.
 *  - The payloads are JPEG/PNG derivatives, which are already compressed —
 *    DEFLATE would burn CPU for ~0% gain.
 *  - Deliverable packaging must be deterministic (fixed timestamps, stable
 *    entry order) so a given project state always produces byte-identical
 *    archives — testable and reproducible.
 *
 * Implements the PKWARE APPNOTE.TXT subset:
 *   local file header (0x04034b50) + central directory header (0x02014b50)
 *   + end of central directory (0x06054b50), version 2.0, stored method,
 *   no data descriptor, single disk, no encryption. CRC-32 is computed with
 *   the native crc32().
 */
final class ZipWriter
{
    private const LOCAL_FILE_HEADER_SIG = 0x04034B50;

    private const CENTRAL_DIR_HEADER_SIG = 0x02014B50;

    private const END_OF_CENTRAL_DIR_SIG = 0x06054B50;

    private const VERSION_NEEDED = 20; // 2.0 — folders + stored files

    private const METHOD_STORED = 0;

    /** Fixed DOS timestamp (2026-01-01 00:00:00) for determinism. */
    private const DOS_TIME = 0;

    private const DOS_DATE = (1 << 5) | 1; // 2026 → (2026-1980)=46 → 46<<9 done below

    /** @var array<int, array{name: string, bytes: string}> */
    private array $entries = [];

    public function add(string $name, string $bytes): void
    {
        $this->entries[] = ['name' => $this->normalizeName($name), 'bytes' => $bytes];
    }

    /** @return int number of entries */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Serialize the archive: local headers + data for every entry, then the
     * central directory and the EOCD. Offsets are computed in one pass.
     */
    public function toBytes(): string
    {
        $out = '';
        $central = '';
        $offset = 0;
        $dosTime = self::packDosTime();
        $dosDate = self::packDosDate();

        foreach ($this->entries as $entry) {
            $name = $entry['name'];
            $nameLength = strlen($name);
            $crc = crc32($entry['bytes']) & 0xFFFFFFFF;
            $size = strlen($entry['bytes']);

            $local = pack(
                'VvvvvvVVVvv',
                self::LOCAL_FILE_HEADER_SIG,
                self::VERSION_NEEDED,
                0, // flags
                self::METHOD_STORED,
                $dosTime,
                $dosDate,
                $crc,
                $size, // compressed (stored → equal)
                $size, // uncompressed
                $nameLength,
                0 // extra length
            ).$name;

            $out .= $local.$entry['bytes'];

            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                self::CENTRAL_DIR_HEADER_SIG,
                20, // version made by (2.0, MS-DOS)
                self::VERSION_NEEDED,
                0, // flags
                self::METHOD_STORED,
                $dosTime,
                $dosDate,
                $crc,
                $size,
                $size,
                $nameLength,
                0, // extra
                0, // comment
                0, // disk start
                0, // internal attrs
                0, // external attrs
                $offset
            ).$name;

            $offset += strlen($local) + $size;
        }

        $centralDirSize = strlen($central);
        $end = pack(
            'VvvvvVVv',
            self::END_OF_CENTRAL_DIR_SIG,
            0, // disk number
            0, // central dir disk
            count($this->entries),
            count($this->entries),
            $centralDirSize,
            $offset, // central dir offset
            0 // comment length
        );

        return $out.$central.$end;
    }

    private function normalizeName(string $name): string
    {
        // Forward slashes only, no leading slash, no traversal — safe entry names.
        $name = str_replace('\\', '/', $name);
        $parts = [];
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    private static function packDosTime(): int
    {
        return self::DOS_TIME; // 00:00:00
    }

    private static function packDosDate(): int
    {
        // DOS date: bits 15-9 year since 1980, 8-5 month, 4-0 day → 2026-01-01
        $year = 2026 - 1980; // 46

        return ($year << 9) | (1 << 5) | 1;
    }
}
