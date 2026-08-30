<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Stores media in durable Vercel Blob storage when configured, with a local
 * public-disk fallback for development and tests.
 */
class MediaStore
{
    private const BLOB_ENDPOINT = 'https://vercel.com/api/blob';

    private const BLOB_DELETE_ENDPOINT = 'https://vercel.com/api/blob/delete';

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const REQUEST_TIMEOUT_SECONDS = 30;

    public function isDurable(): bool
    {
        return $this->blobToken() !== null;
    }

    /**
     * Resolve the browser-facing URL for a stored media path.
     *
     * Durable records keep the full public Blob URL in the path column and are
     * returned unchanged; relative local-disk paths are wrapped with the
     * /storage asset base. Never wrap an absolute URL — doing so produced
     * /storage/https://... dead links for durable photos (2026-08-29).
     */
    public static function publicUrl(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        return str_starts_with($path, 'http')
            ? $path
            : asset('storage/'.ltrim($path, '/'));
    }

    /**
     * @return array{path: string, url: string}
     */
    public function write(string $dir, UploadedFile|string $content): array
    {
        [$bytes, $filename, $mime] = $this->contentDetails($content);

        return $this->writeBytes($dir, $bytes, $filename, $mime);
    }

    /**
     * @return array{path: string, url: string}
     */
    public function writeBytes(
        string $dir,
        string $bytes,
        string $filename,
        string $mime,
        bool $allowOverwrite = false,
    ): array {
        $path = $this->targetPath($dir, $filename);

        if ($this->isDurable()) {
            return $this->writeToBlob($path, $bytes, $mime, $allowOverwrite);
        }

        $disk = Storage::disk('public');
        if (! $disk->put($path, $bytes)) {
            throw new RuntimeException("Unable to write media [{$path}] to the public disk.");
        }

        $url = (string) $disk->url($path);
        if ($url === '') {
            throw new RuntimeException("Unable to resolve a public URL for media [{$path}].");
        }

        return ['path' => $path, 'url' => $url];
    }

    public function read(string $path): string
    {
        if ($this->isHttpPath($path)) {
            try {
                return Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                    ->get($path)
                    ->throw()
                    ->body();
            } catch (Throwable $e) {
                throw new RuntimeException("Unable to read remote media [{$path}].", 0, $e);
            }
        }

        try {
            $bytes = Storage::disk('public')->get($path);
        } catch (Throwable $e) {
            $seedBytes = $this->readBundledSeedAsset($path);
            if ($seedBytes !== null) {
                return $seedBytes;
            }

            throw new RuntimeException("Unable to read local media [{$path}].", 0, $e);
        }

        if (! is_string($bytes)) {
            $seedBytes = $this->readBundledSeedAsset($path);
            if ($seedBytes !== null) {
                return $seedBytes;
            }

            throw new RuntimeException("Unable to read local media [{$path}].");
        }

        return $bytes;
    }

    /**
     * Read an immutable demo asset directly from the deployed bundle when a
     * durable runtime has no lambda-local public-disk copy. This fallback
     * cannot escape seed-storage and never handles user-uploaded Blob URLs.
     */
    private function readBundledSeedAsset(string $path): ?string
    {
        $root = realpath(base_path('seed-storage'));
        if ($root === false) {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', $path), '/');
        if ($relative === '' || str_contains($relative, "\0")) {
            return null;
        }

        $candidate = realpath($root.DIRECTORY_SEPARATOR.$relative);
        if ($candidate === false
            || ! str_starts_with($candidate, $root.DIRECTORY_SEPARATOR)
            || ! is_file($candidate)) {
            return null;
        }

        $bytes = file_get_contents($candidate);

        return is_string($bytes) ? $bytes : null;
    }

    public function exists(string $path): bool
    {
        try {
            if ($this->isHttpPath($path)) {
                return Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                    ->head($path)
                    ->successful();
            }

            return Storage::disk('public')->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    public function delete(string $path): bool
    {
        if ($this->isHttpPath($path)) {
            $token = $this->blobToken();
            if ($token === null) {
                throw new RuntimeException('Cannot delete remote media without BLOB_READ_WRITE_TOKEN.');
            }

            try {
                Http::withHeaders($this->blobHeaders($token, 'application/json'))
                    ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                    ->asJson()
                    ->post(self::BLOB_DELETE_ENDPOINT, ['urls' => [$path]])
                    ->throw();
            } catch (Throwable $e) {
                throw new RuntimeException("Unable to delete remote media [{$path}].", 0, $e);
            }

            return true;
        }

        return Storage::disk('public')->delete($path);
    }

    /**
     * Select the value that belongs in a database path column.
     * Durable records must retain the public Blob URL; local records retain a
     * relative disk path so reads do not accidentally become HTTP requests.
     *
     * @param  array{path?: mixed, url?: mixed}  $stored
     */
    public function recordPath(array $stored): string
    {
        $key = $this->isDurable() ? 'url' : 'path';
        $path = $stored[$key] ?? null;

        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException('Media storage returned an unusable record path.');
        }

        return $path;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function contentDetails(UploadedFile|string $content): array
    {
        if ($content instanceof UploadedFile) {
            $realPath = $content->getRealPath();
            $bytes = is_string($realPath) ? file_get_contents($realPath) : false;
            if (! is_string($bytes)) {
                throw new RuntimeException('Unable to read uploaded media bytes.');
            }

            return [$bytes, $content->hashName(), $content->getMimeType() ?: 'application/octet-stream'];
        }

        return [$content, hash('sha256', $content).'.bin', 'application/octet-stream'];
    }

    /**
     * @return array{path: string, url: string}
     */
    private function writeToBlob(string $path, string $bytes, string $mime, bool $allowOverwrite): array
    {
        $token = $this->blobToken();
        if ($token === null) {
            throw new RuntimeException('Cannot write remote media without BLOB_READ_WRITE_TOKEN.');
        }

        $endpoint = self::BLOB_ENDPOINT.'?pathname='.rawurlencode($path);

        try {
            $response = Http::withHeaders($this->blobHeaders($token, $mime, $allowOverwrite))
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->withBody($bytes, $mime)
                ->put($endpoint)
                ->throw();
            $url = $response->json('url');
        } catch (Throwable $e) {
            // Structured diagnostics (2026-08-29): the PUT succeeded via local
            // curl with identical headers but failed inside the lambda with no
            // visible cause. Log exception class, HTTP status and the response
            // body so Vercel runtime logs carry the root cause.
            $status = isset($response) ? $response->status() : 0;
            Log::error('blob_put_failed', [
                'path' => $path,
                'bytes' => strlen($bytes),
                'mime' => $mime,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'http_status' => $status,
                'response' => isset($response) ? mb_substr($response->body(), 0, 500) : null,
            ]);

            throw new RuntimeException("Unable to write remote media [{$path}].", 0, $e);
        }

        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException("Vercel Blob returned no URL for media [{$path}].");
        }

        return ['path' => $path, 'url' => $url];
    }

    private function targetPath(string $dir, string $filename): string
    {
        $dir = trim($dir, '/');
        $segments = $dir === '' ? [] : explode('/', $dir);
        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            throw new RuntimeException('Media directory contains an invalid path segment.');
        }

        return ($dir === '' ? '' : $dir.'/').ltrim($filename, '/');
    }

    public function isHttpPath(string $path): bool
    {
        return str_starts_with($path, 'http');
    }

    private function blobToken(): ?string
    {
        $token = getenv('BLOB_READ_WRITE_TOKEN');
        if ($token === false) {
            $token = env('BLOB_READ_WRITE_TOKEN');
        }

        return is_string($token) && trim($token) !== '' ? $token : null;
    }

    /**
     * @return array<string, string>
     */
    private function blobHeaders(string $token, string $contentType, bool $allowOverwrite = false): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'x-vercel-blob-store-id' => $this->blobStoreId($token),
            'x-api-version' => '12',
            'x-vercel-blob-access' => 'public',
            'x-add-random-suffix' => '0',
            'x-content-type' => $contentType,
            'Content-Type' => $contentType,
        ];

        if ($allowOverwrite) {
            $headers['x-allow-overwrite'] = '1';
        }

        return $headers;
    }

    private function blobStoreId(string $token): string
    {
        $storeId = getenv('VERCEL_BLOB_STORE_ID');
        if ($storeId === false) {
            $storeId = env('VERCEL_BLOB_STORE_ID');
        }

        if (! is_string($storeId) || trim($storeId) === '') {
            $parts = explode('_', $token);
            $storeId = $parts[3] ?? null;
        }

        if (! is_string($storeId) || trim($storeId) === '') {
            throw new RuntimeException('Unable to resolve the Vercel Blob store ID.');
        }

        return $storeId;
    }
}
