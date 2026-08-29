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
}
