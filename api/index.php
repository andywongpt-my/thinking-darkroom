<?php

/**
 * Vercel serverless entry — Thinking Darkroom.
 *
 * Cold start: copy the build-time seed bundle (database/seed.sqlite +
 * seed-storage/) into /tmp, the only writable location in the lambda.
 * Every cold start serves the same deterministic demo dataset; writes
 * within a warm instance live in /tmp and vanish on recycle (documented
 * serverless limitation for this demo).
 *
 * Runtime-only file: not part of the certified application code.
 */

// ---- release-aware seed unpack (once per deployed bundle) -----------------
$bundleDb = __DIR__.'/../database/seed.sqlite';
$bundleStorage = __DIR__.'/../seed-storage';
$releaseMarker = '/tmp/.thinking-darkroom-release';
$releaseFingerprint = hash('sha256', implode('|', array_map(
    static fn (string $path): string => (string) (is_file($path) ? hash_file('sha256', $path) : ''),
    [
        __FILE__,
        __DIR__.'/../bootstrap/app.php',
        __DIR__.'/../composer.lock',
        __DIR__.'/../vercel.json',
        $bundleDb,
    ],
)));
$runtimeCachePaths = [
    getenv('APP_CONFIG_CACHE') ?: '/tmp/config.php',
    getenv('APP_EVENTS_CACHE') ?: '/tmp/events.php',
    getenv('APP_PACKAGES_CACHE') ?: '/tmp/packages.php',
    getenv('APP_ROUTES_CACHE') ?: '/tmp/routes.php',
    getenv('APP_SERVICES_CACHE') ?: '/tmp/services.php',
];
$needsRefresh = ! is_file($releaseMarker)
    || ! hash_equals($releaseFingerprint, trim((string) file_get_contents($releaseMarker)));

if ($needsRefresh) {
    foreach ($runtimeCachePaths as $cachePath) {
        if (str_starts_with($cachePath, '/tmp/') && is_file($cachePath)) {
            unlink($cachePath);
        }
    }

    if (is_file('/tmp/database.sqlite')) {
        unlink('/tmp/database.sqlite');
    }
    shell_exec('rm -rf /tmp/storage /tmp/views');

    if (is_file($bundleDb)) {
        copy($bundleDb, '/tmp/database.sqlite');
    }
    shell_exec('mkdir -p /tmp/storage/app/public /tmp/storage/framework/sessions /tmp/storage/framework/cache /tmp/storage/framework/views /tmp/storage/logs /tmp/views');
    if (is_dir($bundleStorage)) {
        shell_exec('cp -r '.escapeshellarg($bundleStorage).'/. /tmp/storage/app/public/');
    }
    file_put_contents($releaseMarker, $releaseFingerprint, LOCK_EX);
}

require __DIR__.'/../public/index.php';
