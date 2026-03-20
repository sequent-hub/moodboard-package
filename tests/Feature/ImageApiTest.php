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

        $uploadResponse = $this->post('/api/images/upload', [
            'image' => $file,
            'name' => 'Demo Image',
        ])->assertOk()->assertJsonPath('success', true);

        $imageId = $uploadResponse->json('data.imageId');
        $this->assertNotEmpty($imageId);

        $this->getJson("/api/images/{$imageId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $imageId)
            ->assertJsonPath('data.name', 'Demo Image');

        $this->get("/api/images/{$imageId}/file")
            ->assertOk();

        $this->getJson('/api/images')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['id' => $imageId]);
    }

    public function test_it_bulk_deletes_images(): void
    {
        $first = $this->post('/api/images/upload', [
            'image' => UploadedFile::fake()->image('one.png', 10, 10),
            'name' => 'One',
        ])->json('data.imageId');

        $second = $this->post('/api/images/upload', [
            'image' => UploadedFile::fake()->image('two.png', 10, 10),
            'name' => 'Two',
        ])->json('data.imageId');

        $this->postJson('/api/images/bulk-delete', ['ids' => [$first, $second]])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('images', ['id' => $first]);
        $this->assertDatabaseMissing('images', ['id' => $second]);
    }

    public function test_it_validates_upload_request(): void
    {
        $this->post('/api/images/upload', [
            'image' => UploadedFile::fake()->create('not-image.txt', 10, 'text/plain'),
        ], [
            'Accept' => 'application/json',
        ])->assertStatus(422);
    }
}
