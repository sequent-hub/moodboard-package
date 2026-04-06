<?php

namespace Futurello\MoodBoard\Tests\Feature\Api;

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

    public function test_it_returns_405_for_removed_show_route_404_for_download_and_501_on_destroy(): void
    {
        $this->getJson('/api/v2/images/not-existing-id')
            ->assertStatus(405);

        $this->get('/api/v2/images/not-existing-id/download')
            ->assertStatus(404);

        $this->deleteJson('/api/v2/images/not-existing-id')
            ->assertStatus(501)
            ->assertJsonPath('success', false);
    }

    public function test_it_returns_501_for_bulk_delete_payload_on_v2(): void
    {
        $this->postJson('/api/v2/images/bulk-delete', [])
            ->assertStatus(501);

        $this->postJson('/api/v2/images/bulk-delete', [
            'ids' => ['not-existing-id'],
        ])->assertStatus(501);
    }

    public function test_it_uploads_same_content_twice_and_returns_urls(): void
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

        $firstResponse = $this->post('/api/v2/images/upload', [
            'image' => $firstImage,
            'name' => 'Dedup A',
        ])->assertOk()->assertJsonPath('success', true);

        $secondResponse = $this->post('/api/v2/images/upload', [
            'image' => $secondImage,
            'name' => 'Dedup B',
        ])->assertOk()->assertJsonPath('success', true);

        $firstUrl = $firstResponse->json('data.url');
        $secondUrl = $secondResponse->json('data.url');

        $this->assertNotEmpty($firstUrl);
        $this->assertNotEmpty($secondUrl);
    }
}
