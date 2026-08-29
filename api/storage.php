<?php

/**
 * Vercel runtime shim: stream /storage/* .
 *
 * Snapshot semantics: this shim only ever serves the IMMUTABLE seed
 * bundle (seed-storage/) that ships inside the lambda. User uploads
 * never land here — in durable mode they go to Vercel Blob and their
 * Photo rows carry absolute blob URLs, so /storage/* is exclusively
 * read-only demo assets. Each instance unpacks the same bundle into
 * its own /tmp, which is safe precisely because the content is
 * immutable and identical across instances.
 *
 * Durable mode additionally guarantees /tmp/storage is never treated
 * as writable state (2026-08-29 incident: uploads written to one
 * lambda's /tmp were invisible to every other instance).
 *
 * There is no nginx on serverless, so this lambda replaces the
 * `storage:link` static-serving layer with identical URL semantics.
 */

declare(strict_types=1);

$bundleStorage = __DIR__.'/../seed-storage';
$bundleDb = __DIR__.'/../database/seed.sqlite';
$releaseMarker = '/tmp/.thinking-darkroom-storage-release';
$publicRoot = '/tmp/storage/app/public';

// Fingerprint now covers the seed-storage CONTENTS as well as the shim
// and seed DB, so changed bundle media can no longer be masked by a
// stale marker (audit P2-1 / stale-cache finding).
$dirHash = 'none';
if (is_dir($bundleStorage)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($bundleStorage, FilesystemIterator::SKIP_DOTS)
    );
    $files = [];
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if ($f->isFile()) {
            $files[] = $f->getPathname().'|'.$f->getSize().'|'.hash_file('sha256', $f->getPathname());
        }
    }
    sort($files);
    $dirHash = hash('sha256', implode("\n", $files));
}

$releaseFingerprint = hash('sha256', implode('|', [
    (string) hash_file('sha256', __FILE__),
    (string) (is_file($bundleDb) ? hash_file('sha256', $bundleDb) : ''),
    $dirHash,
]));

$needsRefresh = ! is_file($releaseMarker)
    || ! hash_equals($releaseFingerprint, trim((string) file_get_contents($releaseMarker)));

if ($needsRefresh && is_dir($bundleStorage)) {
    // Atomic-ish refresh: build in a staging dir, then swap, so a
    // concurrent first request can never observe a half-copied tree.
    $staging = '/tmp/storage/app/.public.staging-'.getmypid();
    $target = '/tmp/storage/app/public';

    shell_exec('rm -rf '.escapeshellarg($staging));
    $mkdirOk = shell_exec('mkdir -p '.escapeshellarg(dirname($staging)).' && cp -r '.escapeshellarg($bundleStorage).' '.escapeshellarg($staging));
    $copied = is_dir($staging) && count(glob($staging.'/*', GLOB_NOSORT)) > 0;

    if ($copied) {
        if (is_dir($target)) {
            shell_exec('rm -rf '.escapeshellarg($target));
        }
        // rename() is atomic within the same filesystem (/tmp).
        if (! @rename($staging, $target)) {
            shell_exec('cp -r '.escapeshellarg($staging).' '.escapeshellarg($target));
        }
        @rmdir($staging);
        file_put_contents($releaseMarker, $releaseFingerprint, LOCK_EX);
    }
    // If the copy failed, do NOT write the marker: the next request
    // retries instead of pinning a broken refresh (audit P2-1).
}

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$rel = ltrim(substr($uri, strlen('/storage/')), '/');

if ($rel === '' || str_contains($rel, '..')) {
    http_response_code(404);
    exit('Not found');
}

$root = realpath($publicRoot);
$file = $root === false ? false : realpath($root.'/'.$rel);

if ($file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file)) {
    http_response_code(404);
    exit('Not found');
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$types = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
    'webp' => 'image/webp',
    'json' => 'application/json',
];
header('Content-Type: '.($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: '.(string) filesize($file));
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($file);
