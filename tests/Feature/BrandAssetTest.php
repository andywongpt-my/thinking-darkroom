<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrandAssetTest extends TestCase
{
    public function test_the_application_shell_exposes_a_real_thinking_darkroom_favicon(): void
    {
        $shell = file_get_contents(resource_path('views/app.blade.php'));

        self::assertIsString($shell);
        self::assertStringContainsString('href="/favicon.svg"', $shell);
        self::assertFileExists(public_path('favicon.svg'));
        self::assertGreaterThan(0, filesize(public_path('favicon.svg')));
        self::assertFileExists(public_path('favicon.ico'));
        self::assertGreaterThan(0, filesize(public_path('favicon.ico')));
    }

    public function test_the_vercel_static_build_copies_the_svg_favicon(): void
    {
        $buildScript = file_get_contents(base_path('vercel-build.sh'));

        self::assertIsString($buildScript);
        self::assertStringContainsString('public/favicon.svg', $buildScript);
    }

    public function test_branding_fallbacks_never_revert_to_laravel(): void
    {
        $appConfig = file_get_contents(config_path('app.php'));
        $appShell = file_get_contents(resource_path('views/app.blade.php'));
        $clientBootstrap = file_get_contents(resource_path('js/app.tsx'));
        $mailConfig = file_get_contents(config_path('mail.php'));

        self::assertIsString($appConfig);
        self::assertIsString($appShell);
        self::assertIsString($clientBootstrap);
        self::assertIsString($mailConfig);
        self::assertStringContainsString("env('APP_NAME', 'Thinking Darkroom')", $appConfig);
        self::assertStringContainsString("config('app.name', 'Thinking Darkroom')", $appShell);
        self::assertStringContainsString("|| 'Thinking Darkroom'", $clientBootstrap);
        self::assertStringContainsString("env('MAIL_FROM_NAME', env('APP_NAME', 'Thinking Darkroom'))", $mailConfig);
    }
}
