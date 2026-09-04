<?php

namespace Tests\Unit;

use App\Services\Reports\ZipWriter;
use PHPUnit\Framework\TestCase;

/**
 * ZipWriter — hand-rolled stored-mode ZIP packager (no php-zip on the host
 * or in the Vercel runtime; deliverables are already-compressed images, so
 * stored mode loses nothing).
 *
 * The strongest oracle available: PHP's Phar can read ZIP-based archives,
 * so every produced archive is parsed back with PharData and its entries
 * verified byte-for-byte. Structural magic numbers are additionally
 * asserted directly.
 */
class ZipWriterTest extends TestCase
{
    private function unpack(array $entries): array
    {
        $zip = new ZipWriter;
        foreach ($entries as $name => $bytes) {
            $zip->add($name, $bytes);
        }
        $bytes = $zip->toBytes();
        // PharData refuses extensionless temp names — give it a .zip suffix.
        $tmp = sys_get_temp_dir().'/'.uniqid('zipw').'.zip';
        file_put_contents($tmp, $bytes);

        $phar = new \PharData($tmp);
        $parsed = [];
        foreach (new \RecursiveIteratorIterator($phar) as $file) {
            /** @var \PharFileInfo $file */
            $parsed[$file->getFilename()] = file_get_contents($file->getPathname());
        }

        unlink($tmp);

        return $parsed;
    }

    public function test_round_trips_entries_byte_for_byte(): void
    {
        $jpegLike = "\xFF\xD8\xFF\xE0".random_bytes(2048)."\xFF\xD9";
        $markdown = "# Session Report\n\n- one\n- two\n";

        $parsed = $this->unpack([
            'derivatives/IMG_0001.jpg' => $jpegLike,
            'SESSION-REPORT.md' => $markdown,
        ]);

        $this->assertSame($jpegLike, $parsed['IMG_0001.jpg']);
        $this->assertSame($markdown, $parsed['SESSION-REPORT.md']);
    }

    public function test_produces_deterministic_bytes(): void
    {
        $a = new ZipWriter;
        $b = new ZipWriter;
        foreach (['a.txt' => 'alpha', 'b.txt' => 'beta'] as $name => $bytes) {
            $a->add($name, $bytes);
            $b->add($name, $bytes);
        }

        $this->assertSame($a->toBytes(), $b->toBytes());
    }

    public function test_starts_with_local_file_header_signature(): void
    {
        $zip = new ZipWriter;
        $zip->add('x.txt', 'hello');

        $this->assertSame("PK\x03\x04", substr($zip->toBytes(), 0, 4));
        // Central directory + EOCD markers must also be present.
        $this->assertStringContainsString("PK\x01\x02", $zip->toBytes());
        $this->assertStringContainsString("PK\x05\x06", $zip->toBytes());
    }

    public function test_normalizes_unsafe_entry_names(): void
    {
        $parsed = $this->unpack([
            '/abs/../../etc/passwd' => 'nope',
            'ok/name with spaces.txt' => 'fine',
        ]);

        // No absolute path or traversal survives; content is intact.
        $this->assertArrayHasKey('passwd', $parsed);
        $this->assertSame('nope', $parsed['passwd']);
        $this->assertArrayHasKey('name with spaces.txt', $parsed);
        foreach (array_keys($parsed) as $name) {
            $this->assertStringNotContainsString('..', $name);
            $this->assertStringStartsNotWith('/', $name);
        }
    }

    public function test_empty_archive_is_a_valid_zip(): void
    {
        $zip = new ZipWriter;
        $bytes = $zip->toBytes();

        $this->assertSame("PK\x05\x06", substr($bytes, -22, 4));
        $tmp = sys_get_temp_dir().'/'.uniqid('zipw').'.zip';
        file_put_contents($tmp, $bytes);
        $phar = new \PharData($tmp);
        $this->assertCount(0, iterator_to_array(new \RecursiveIteratorIterator($phar)));
        unlink($tmp);
    }

    public function test_count_reflects_added_entries(): void
    {
        $zip = new ZipWriter;
        $this->assertSame(0, $zip->count());
        $zip->add('one.txt', '1');
        $zip->add('two.txt', '2');
        $this->assertSame(2, $zip->count());
    }
}
