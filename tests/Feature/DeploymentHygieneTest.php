<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentHygieneTest extends TestCase
{
    public function test_vercel_never_uploads_a_local_vite_hot_file(): void
    {
        $vercelIgnore = file_get_contents(base_path('.vercelignore'));

        self::assertIsString($vercelIgnore);
        self::assertStringContainsString('/public/hot', $vercelIgnore);
    }

    public function test_production_uses_a_nonpublic_vite_hot_path(): void
    {
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));

        self::assertIsString($provider);
        self::assertStringContainsString("if (\$this->app->environment('production')) {", $provider);
        self::assertStringContainsString("Vite::useHotFile(storage_path('framework/vite.hot'));", $provider);
    }
}
