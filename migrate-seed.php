<?php

/**
 * Vercel build-time bootstrap: fresh seeded sqlite + demo media bundle.
 *
 * Runs during `composer run vercel` (build phase, writable filesystem).
 * Outputs (bundled into the lambda):
 *   - database/seed.sqlite        → full demo dataset (both seeders)
 *   - seed-storage/               → public disk files (project photos)
 *
 * At cold start api/index.php copies these into /tmp (the only writable
 * location at runtime). Runtime-only file: not part of certified app code.
 * seed.sqlite contains only demo data — never secrets, never committed.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(ConsoleKernel::class);

$env = [
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => __DIR__.'/database/seed.sqlite',
    'SESSION_DRIVER' => 'file',
    'CACHE_STORE' => 'file',
];

foreach ($env as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}

$db = __DIR__.'/database/seed.sqlite';
if (file_exists($db)) {
    unlink($db);
}
touch($db);

$status = $kernel->call('migrate', ['--force' => true]);
echo "migrate: ".($status === 0 ? 'OK' : "FAILED ({$status})")."\n";
if ($status !== 0) {
    exit((int) $status);
}

foreach (['DatabaseSeeder', 'Sprint3CullingSeeder'] as $seeder) {
    $status = $kernel->call('db:seed', ['--force' => true, '--class' => $seeder]);
    echo "db:seed {$seeder}: ".($status === 0 ? 'OK' : "FAILED ({$status})")."\n";
    if ($status !== 0) {
        exit((int) $status);
    }
}

// Bundle the seeded public-disk media (photos) for the read-only lambda.
$src = rtrim(getenv('LARAVEL_STORAGE_PATH') ?: sys_get_temp_dir().'/storage', '/').'/app/public';
$dst = __DIR__.'/seed-storage';

if (is_dir($dst)) {
    shell_exec('rm -rf '.escapeshellarg($dst));
}
if (! is_dir($src)) {
    fwrite(STDERR, "seeded public disk missing: {$src}\n");
    exit(1);
}
shell_exec('mkdir -p '.escapeshellarg($dst).' && cp -r '.escapeshellarg($src.'/.').' '.escapeshellarg($dst));

echo 'seed.sqlite: '.number_format(filesize($db))." bytes\n";
echo 'seed-storage files: '.iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dst, FilesystemIterator::SKIP_DOTS)))."\n";
echo "OK\n";
