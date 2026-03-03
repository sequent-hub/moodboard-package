<?php

namespace Futurello\MoodBoard\Tests\Feature\Api;

use Futurello\MoodBoard\Models\Image;
use Futurello\MoodBoard\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImageApiContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_it_returns_404_for_non_existing_image_on_show(): void
    {
        $this->getJson('/api/images/not-existing-id')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_it_returns_500_for_non_existing_image_on_file_and_destroy(): void
    {
        $this->get('/api/images/not-existing-id/file')
            ->assertStatus(500)
            ->assertJsonPath('success', false);

        $this->deleteJson('/api/images/not-existing-id')
            ->assertStatus(500)
            ->assertJsonPath('success', false);
    }

    public function test_it_returns_404_if_image_record_exists_but_file_missing(): void
    {
        $image = Image::create([
            'name' => 'Missing physical file',
            'original_name' => 'missing.png',
            'path' => 'images/does-not-exist.png',
            'mime_type' => 'image/png',
            'size' => 10,
            'width' => 1,
            'height' => 1,
            'hash' => 'missing-physical-file',
        ]);

        $this->get("/api/images/{$image->id}/file")
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_it_validates_bulk_delete_payload(): void
    {
        $this->postJson('/api/images/bulk-delete', [])
            ->assertStatus(422);

        $this->postJson('/api/images/bulk-delete', [
            'ids' => ['not-existing-id'],
        ])->assertStatus(422);
    }

    public function test_it_reuses_existing_image_for_same_binary_content(): void
    {
        $pngContent = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR42mP8z8BQDwAFgwJ/lQf5QwAAAABJRU5ErkJggg=='
        );
        $this->assertNotFalse($pngContent);
        $tmpPath = tempnam(sys_get_temp_dir(), 'mb_dedup_png_');
        $this->assertNotFalse($tmpPath);
        file_put_contents($tmpPath, $pngContent);

        $firstImage = new UploadedFile($tmpPath, 'dedup-a.png', 'image/png', null, true);
        $secondImage = new UploadedFile($tmpPath, 'dedup-b.png', 'image/png', null, true);

        $firstResponse = $this->post('/api/images/upload', [
            'image' => $firstImage,
            'name' => 'Dedup A',
        ])->assertOk()->assertJsonPath('success', true);

        $secondResponse = $this->post('/api/images/upload', [
            'image' => $secondImage,
            'name' => 'Dedup B',
        ])->assertOk()->assertJsonPath('success', true);

        $firstId = $firstResponse->json('data.imageId');
        $secondId = $secondResponse->json('data.imageId');

        $this->assertNotEmpty($firstId);
        $this->assertNotEmpty($secondId);
        $this->assertSame($firstId, $secondId);
    }
}
