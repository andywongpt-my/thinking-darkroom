<?php

namespace Tests\Unit;

use App\Models\Photo;
use App\Services\ProposalApplicator;
use ReflectionMethod;
use Tests\TestCase;

/**
 * derivativePath() must always yield a STORE-RELATIVE pathname, never an
 * absolute URL. Durable photos keep an absolute Blob URL in the path column;
 * taking dirname() of that produced double-prefixed blob pathnames
 * (https://…store/https%3A//…store/project-1/x.retouched.jpg) in the
 * 2026-08-29 production E2E.
 */
class ProposalApplicatorDerivativePathTest extends TestCase
{
    private function resolveDerivativePath(Photo $photo): string
    {
        $applicator = app(ProposalApplicator::class);
        $method = new ReflectionMethod($applicator, 'derivativePath');

        return $method->invoke($applicator, $photo);
    }

    public function test_local_relative_path_keeps_its_directory(): void
    {
        $photo = new Photo(['path' => 'project-1/original.jpg']);

        $this->assertSame(
            'project-1/original.retouched.jpg',
            $this->resolveDerivativePath($photo),
        );
    }

    public function test_local_path_without_directory_has_no_leading_separator(): void
    {
        $photo = new Photo(['path' => 'original.jpg']);

        $this->assertSame(
            'original.retouched.jpg',
            $this->resolveDerivativePath($photo),
        );
    }

    public function test_durable_absolute_url_yields_a_store_relative_pathname(): void
    {
        $photo = new Photo([
            'path' => 'https://store.public.blob.vercel-storage.com/project-1/original.jpg',
        ]);

        $this->assertSame(
            'project-1/original.retouched.jpg',
            $this->resolveDerivativePath($photo),
        );
    }

    public function test_durable_url_with_query_string_ignores_the_query(): void
    {
        $photo = new Photo([
            'path' => 'https://store.public.blob.vercel-storage.com/project-2/original.jpg?download=1',
        ]);

        $this->assertSame(
            'project-2/original.retouched.jpg',
            $this->resolveDerivativePath($photo),
        );
    }

    public function test_durable_url_at_store_root_has_no_leading_separator(): void
    {
        $photo = new Photo([
            'path' => 'https://store.public.blob.vercel-storage.com/original.jpg',
        ]);

        $this->assertSame(
            'original.retouched.jpg',
            $this->resolveDerivativePath($photo),
        );
    }

    public function test_derived_name_never_contains_the_original_extension_twice(): void
    {
        $photo = new Photo([
            'path' => 'https://store.public.blob.vercel-storage.com/project-1/frame.jpeg',
        ]);

        $path = $this->resolveDerivativePath($photo);

        $this->assertSame('project-1/frame.retouched.jpg', $path);
        $this->assertSame(1, substr_count($path, '.jpg'));
    }
}
