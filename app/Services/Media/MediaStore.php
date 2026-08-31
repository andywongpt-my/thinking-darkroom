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
     * True when the runtime is a durable DEPLOYMENT whose DB rows survive
     * cold starts — i.e. the actual Vercel serverless entry with an external
     * DB. In that runtime a local public-disk write is a silent data-loss
     * trap (Sol P0-1): the row would reference a lambda-local /tmp path that
     * vanishes on the next instance. A local dev machine or test suite may
     * legitimately use a MySQL test database with a local disk, so the DB
     * driver alone must not decide this — the serverless runtime must.
     */
    public static function requiresDurableMedia(): bool
    {
        if (app()->runningInConsole() && ! self::isServerlessRuntime()) {
            return false;
        }

        if (self::isServerlessRuntime()) {
            // Resolve the driver through the resolved config when available
            // (config may be cached — vercel.json sets APP_CONFIG_CACHE — and
            // env() returns null for cached keys), then fall back to the raw
            // environment. DATABASE_URL/DB_URL with a SQL scheme also imply a
            // durable external DB even when DB_CONNECTION itself is absent.
            $default = strtolower((string) (config('database.default') ?: getenv('DB_CONNECTION') ?: env('DB_CONNECTION', 'sqlite')));

            if (in_array($default, ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], true)) {
                return true;
            }

            foreach (['DATABASE_URL', 'DB_URL'] as $urlKey) {
                $url = (string) (config("database.connections.{$default}.url") ?: getenv($urlKey) ?: env($urlKey, ''));
                if (preg_match('#^(mysql|postgres(ql)?|mariadb|sqlsrv)://#', strtolower($url)) === 1) {
                    return true;
                }
            }

            return false;
        }

        return app()->environment('production');
    }

    private static function isServerlessRuntime(): bool
    {
        return getenv('VERCEL') === '1';
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

        if (self::isTrustedBlobUrl($path)) {
            return $path;
        }

        return self::hasUrlScheme($path)
            ? null
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

        // Fail closed (Sol P0-1): a durable runtime (external DB / production)
        // must never silently fall back to lambda-local storage — the DB row
        // would outlive the ephemeral bytes. Only local/test sqlite runtimes
        // may use the public disk.
        if (self::requiresDurableMedia()) {
            throw new RuntimeException(
                'Durable media backend unavailable: refusing to write client media to ephemeral local storage.',
            );
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
        if ($this->isTrustedBlobUrl($path)) {
            try {
                return Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                    ->withoutRedirecting()
                    ->get($path)
                    ->throw()
                    ->body();
            } catch (Throwable $e) {
                throw new RuntimeException("Unable to read remote media [{$path}].", 0, $e);
            }
        }

        $this->rejectUntrustedRemotePath($path);

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
            if ($this->isTrustedBlobUrl($path)) {
                return Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                    ->withoutRedirecting()
                    ->head($path)
                    ->successful();
            }

            if (self::hasUrlScheme($path)) {
                return false;
            }

            return Storage::disk('public')->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    public function delete(string $path): bool
    {
        if ($this->isTrustedBlobUrl($path)) {
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

        $this->rejectUntrustedRemotePath($path);

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

    /**
     * True only for HTTPS public Vercel Blob URLs emitted by this application's
     * durable storage flow. Treating every string beginning with "http" as
     * trusted would let a corrupt DB row turn this service into an SSRF client.
     */
    public static function isTrustedBlobUrl(string $path): bool
    {
        $parts = parse_url($path);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;
        $isPublicBlobHost = str_ends_with($host, '.public.blob.vercel-storage.com')
            || $host === 'blob.vercel-storage.com'; // legacy durable records

        return $scheme === 'https'
            && $host !== ''
            && $isPublicBlobHost
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ($port === null || $port === 443);
    }

    /**
     * Compatibility shim for the existing derivative idempotency check.
     * New code should use isTrustedBlobUrl() so the trust boundary is explicit.
     */
    public function isHttpPath(string $path): bool
    {
        return self::isTrustedBlobUrl($path);
    }

    private static function hasUrlScheme(string $path): bool
    {
        return preg_match('/^[a-z][a-z0-9+.-]*:/i', trim($path)) === 1;
    }

    private function rejectUntrustedRemotePath(string $path): void
    {
        if (self::hasUrlScheme($path)) {
            throw new RuntimeException('Media path is not an allow-listed HTTPS Vercel Blob URL.');
        }
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
