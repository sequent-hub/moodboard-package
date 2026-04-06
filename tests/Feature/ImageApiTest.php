<?php

namespace Futurello\MoodBoard\Tests\Feature;

use Futurello\MoodBoard\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImageApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_it_uploads_and_returns_image_data(): void
    {
        $file = UploadedFile::fake()->image('demo.png', 120, 80);

        $uploadResponse = $this->post('/api/v2/images/upload', [
            'image' => $file,
            'name' => 'Demo Image',
        ])->assertOk()->assertJsonPath('success', true);

        $url = $uploadResponse->json('data.url');
        $this->assertNotEmpty($url);
        $uploadResponse->assertJsonPath('data.name', 'Demo Image');
    }

    public function test_it_returns_not_implemented_for_bulk_delete_on_v2(): void
    {
        $this->post('/api/v2/images/upload', [
            'image' => UploadedFile::fake()->image('one.png', 10, 10),
            'name' => 'One',
        ])->assertOk();

        $this->post('/api/v2/images/upload', [
            'image' => UploadedFile::fake()->image('two.png', 10, 10),
            'name' => 'Two',
        ])->assertOk();

        $this->postJson('/api/v2/images/bulk-delete', ['ids' => ['any', 'values']])
            ->assertStatus(501)
            ->assertJsonPath('success', false);
    }

    public function test_it_validates_upload_request(): void
    {
        $this->post('/api/v2/images/upload', [
            'image' => UploadedFile::fake()->create('not-image.txt', 10, 'text/plain'),
        ], [
            'Accept' => 'application/json',
        ])->assertStatus(422);
    }
}
