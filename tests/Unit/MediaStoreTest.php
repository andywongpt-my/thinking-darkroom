<?php

namespace Tests\Unit;

use App\Services\Media\MediaStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MediaStoreTest extends TestCase
{
    private string|false $previousBlobToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousBlobToken = getenv('BLOB_READ_WRITE_TOKEN');
        putenv('BLOB_READ_WRITE_TOKEN');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        if ($this->previousBlobToken === false) {
            putenv('BLOB_READ_WRITE_TOKEN');
        } else {
            putenv('BLOB_READ_WRITE_TOKEN='.$this->previousBlobToken);
        }

        parent::tearDown();
    }

    public function test_local_write_returns_a_relative_path_and_public_url(): void
    {
        $file = UploadedFile::fake()->image('portrait.jpg');
        $result = app(MediaStore::class)->write('project-1', $file);

        $this->assertArrayHasKey('path', $result);
        $this->assertArrayHasKey('url', $result);
        $this->assertFalse(str_starts_with($result['path'], 'http'));
        Storage::disk('public')->assertExists($result['path']);
        $this->assertNotEmpty(parse_url($result['url']));
    }

    public function test_durable_write_sends_raw_bytes_to_vercel_blob(): void
    {
        putenv('BLOB_READ_WRITE_TOKEN=vercel_blob_rw_store-test_secret');
        $file = UploadedFile::fake()->image('portrait.jpg');
        $bytes = file_get_contents($file->getRealPath());
        $blobUrl = 'https://blob.vercel-storage.com/project-1/portrait.jpg';

        Http::fake([
            'https://vercel.com/api/blob*' => Http::response(['url' => $blobUrl], 200),
        ]);

        $result = app(MediaStore::class)->write('project-1', $file);

        $this->assertSame($blobUrl, $result['url']);
        $this->assertSame('project-1/'.basename($result['path']), $result['path']);
        $request = Http::recorded()[0][0];
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        $this->assertSame('PUT', $request->method());
        $this->assertSame($result['path'], $query['pathname']);
        $this->assertSame('Bearer vercel_blob_rw_store-test_secret', $request->header('Authorization')[0]);
        $this->assertSame('store-test', $request->header('x-vercel-blob-store-id')[0]);
        $this->assertSame('12', $request->header('x-api-version')[0]);
        $this->assertSame('0', $request->header('x-add-random-suffix')[0]);
        $this->assertSame($bytes, $request->body());
    }

    public function test_write_bytes_uses_explicit_filename_and_mime_for_local_storage(): void
    {
        $result = app(MediaStore::class)->writeBytes('project-1', 'jpeg-bytes', 'photo.jpg', 'image/jpeg');

        $this->assertSame('project-1/photo.jpg', $result['path']);
        $this->assertArrayHasKey('url', $result);
        $this->assertTrue(app(MediaStore::class)->exists($result['path']));
        Storage::disk('public')->assertExists($result['path']);
    }

    public function test_write_bytes_sends_explicit_filename_and_mime_to_vercel_blob(): void
    {
        putenv('BLOB_READ_WRITE_TOKEN=vercel_blob_rw_store-test_secret');
        $blobUrl = 'https://blob.vercel-storage.com/project-1/photo.jpg';

        Http::fake([
            'https://vercel.com/api/blob*' => Http::response(['url' => $blobUrl], 200),
        ]);

        $result = app(MediaStore::class)->writeBytes('project-1', 'jpeg-bytes', 'photo.jpg', 'image/jpeg');

        $this->assertSame('project-1/photo.jpg', $result['path']);
        $this->assertSame($blobUrl, $result['url']);
        $request = Http::recorded()[0][0];
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        $this->assertSame('project-1/photo.jpg', $query['pathname']);
        $this->assertSame('image/jpeg', $request->header('Content-Type')[0]);
        $this->assertSame('jpeg-bytes', $request->body());
    }

    public function test_exists_checks_remote_media_with_a_head_request_and_returns_false_on_failure(): void
    {
        $existingUrl = 'https://blob.vercel-storage.com/project-1/existing.jpg';
        $missingUrl = 'https://blob.vercel-storage.com/project-1/missing.jpg';

        Http::fake([
            $existingUrl => Http::response([], 200),
            $missingUrl => Http::response([], 404),
        ]);

        $store = app(MediaStore::class);

        $this->assertTrue($store->exists($existingUrl));
        $this->assertFalse($store->exists($missingUrl));
        $this->assertSame(
            ['HEAD', 'HEAD'],
            Http::recorded()->map(fn ($recorded) => $recorded[0]->method())->all(),
        );
    }

    public function test_durable_read_and_delete_use_http_with_failures_exposed(): void
    {
        putenv('BLOB_READ_WRITE_TOKEN=vercel_blob_rw_store-test_secret');
        $blobUrl = 'https://blob.vercel-storage.com/project-1/photo.jpg';

        Http::fake([
            'https://blob.vercel-storage.com/*' => Http::response('image-bytes', 200),
            'https://vercel.com/api/blob/delete' => Http::response([], 200),
        ]);

        $store = app(MediaStore::class);
        $this->assertSame('image-bytes', $store->read($blobUrl));
        $this->assertTrue($store->delete($blobUrl));

        // Note: a second Http::fake() call merges rather than replaces stubs,
        // so this failure case lives in its own test with a clean fake set.
        Http::fake([
            'https://blob.vercel-storage.com/*' => Http::response(['error' => 'gone'], 500),
        ]);
    }

    public function test_durable_read_failure_is_not_silent(): void
    {
        putenv('BLOB_READ_WRITE_TOKEN=vercel_blob_rw_store-test_secret');
        $blobUrl = 'https://blob.vercel-storage.com/project-1/photo.jpg';

        Http::fake([
            'https://blob.vercel-storage.com/*' => Http::response(['error' => 'gone'], 500),
        ]);

        $this->expectException(RuntimeException::class);
        app(MediaStore::class)->read($blobUrl);
    }

    public function test_durable_write_failure_is_not_silent(): void
    {
        putenv('BLOB_READ_WRITE_TOKEN=vercel_blob_rw_store-test_secret');
        Http::fake([
            'https://vercel.com/api/blob*' => Http::response(['error' => 'nope'], 500),
        ]);

        $this->expectException(RuntimeException::class);
        app(MediaStore::class)->write('project-1', 'bytes');
    }

    public function test_public_url_passes_absolute_urls_through_unwrapped(): void
    {
        $blobUrl = 'https://store.public.blob.vercel-storage.com/project-1/photo.png';

        $this->assertSame($blobUrl, MediaStore::publicUrl($blobUrl));
        $this->assertStringStartsWith('https://blob.vercel-storage.com', MediaStore::publicUrl('https://blob.vercel-storage.com/x.jpg'));
    }

    public function test_public_url_wraps_only_relative_paths_with_storage_base(): void
    {
        $url = MediaStore::publicUrl('project-1/photo.png');

        $this->assertStringEndsWith('/storage/project-1/photo.png', (string) $url);
        $this->assertStringContainsString('/storage/project-1/photo.png', (string) $url);
        $this->assertNull(MediaStore::publicUrl(null));
        $this->assertNull(MediaStore::publicUrl('   '));
    }

    public function test_public_url_never_nests_an_absolute_url_under_storage(): void
    {
        // Regression: durable photos rendered as /storage/https://... dead links.
        $blobUrl = 'https://zav0b2xgow4pv27p.public.blob.vercel-storage.com/project-1/photo.png';

        $this->assertSame($blobUrl, MediaStore::publicUrl($blobUrl));
        $this->assertStringNotContainsString('/storage/http', (string) MediaStore::publicUrl($blobUrl));
    }

    public function test_untrusted_remote_urls_are_never_fetched_as_media(): void
    {
        Http::fake();
        $store = app(MediaStore::class);

        try {
            $store->read('http://169.254.169.254/latest/meta-data');
            $this->fail('An untrusted remote URL must not be fetched.');
        } catch (RuntimeException) {
            Http::assertNothingSent();
        }

        $this->assertFalse($store->exists('https://attacker.example/internal'));
        Http::assertNothingSent();
    }

    public function test_untrusted_remote_urls_are_never_deleted_or_exposed(): void
    {
        putenv('BLOB_READ_WRITE_TOKEN=vercel_blob_rw_store-test_secret');
        Http::fake();
        $store = app(MediaStore::class);

        try {
            $store->delete('https://attacker.example/victim.jpg');
            $this->fail('An untrusted remote URL must not be sent to the Blob delete API.');
        } catch (RuntimeException) {
            Http::assertNothingSent();
        }

        $this->assertNull(MediaStore::publicUrl('https://attacker.example/victim.jpg'));
    }
}
