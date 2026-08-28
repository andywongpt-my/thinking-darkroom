<?php

/**
 * Vercel runtime shim: stream /storage/* from the lambda bundle.
 *
 * Cold start unpacks the seeded public disk (built by migrate-seed.php)
 * into /tmp/storage/app/public. There is no nginx on serverless, so this
 * lambda replaces the `storage:link` static-serving layer with identical
 * URL semantics — zero application code changes.
 */

declare(strict_types=1);

$seedMarker = '/tmp/.thinking-darkroom-seeded';
$bundleStorage = __DIR__.'/../seed-storage';

if (! file_exists($seedMarker) && is_dir($bundleStorage)) {
    shell_exec('mkdir -p /tmp/storage/app && cp -r '.escapeshellarg($bundleStorage).' /tmp/storage/app/public');
    touch($seedMarker);
}

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$rel = ltrim(substr($uri, strlen('/storage/')), '/');

if ($rel === '' || str_contains($rel, '..')) {
    http_response_code(404);
    exit('Not found');
}

$root = realpath('/tmp/storage/app/public');
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
