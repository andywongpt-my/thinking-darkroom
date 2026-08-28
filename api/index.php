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

// ---- cold-start seed unpack (once per lambda instance) --------------------
$seedMarker = '/tmp/.thinking-darkroom-seeded';
$bundleDb = __DIR__.'/../database/seed.sqlite';
$bundleStorage = __DIR__.'/../seed-storage';

if (! file_exists($seedMarker)) {
    if (is_file($bundleDb)) {
        copy($bundleDb, '/tmp/database.sqlite');
    }
    shell_exec('mkdir -p /tmp/storage/app/public /tmp/storage/framework/sessions /tmp/storage/framework/cache /tmp/storage/framework/views /tmp/storage/logs /tmp/views');
    if (is_dir($bundleStorage)) {
        shell_exec('cp -r '.escapeshellarg($bundleStorage).'/. /tmp/storage/app/public/');
    }
    touch($seedMarker);
}

require __DIR__.'/../public/index.php';
