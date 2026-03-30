<?php

namespace Futurello\MoodBoard\Tests\Feature\Api;

use Futurello\MoodBoard\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImageV2UrlDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_v2_upload_returns_v2_download_url_in_payload(): void
    {
        $uploadResponse = $this->post('/api/v2/images/upload', [
            'image' => UploadedFile::fake()->image('v2-diagnostic.png', 64, 64),
            'name' => 'V2 Diagnostic Image',
        ])->assertOk()->assertJsonPath('success', true);

        $imageId = $uploadResponse->json('data.imageId');
        $url = $uploadResponse->json('data.url');

        $this->assertNotEmpty($imageId, 'Missing imageId in /api/v2/images/upload response.');
        $this->assertNotEmpty($url, 'Missing data.url in /api/v2/images/upload response.');
        $this->assertStringContainsString(
            "/api/v2/images/{$imageId}/download",
            $url,
            'Expected data.url to point to v2 image download route.'
        );
    }

    public function test_v2_upload_url_is_reachable_via_get_request(): void
    {
        $uploadResponse = $this->post('/api/v2/images/upload', [
            'image' => UploadedFile::fake()->image('v2-reachable.png', 32, 32),
            'name' => 'V2 Reachability Image',
        ])->assertOk()->assertJsonPath('success', true);

        $url = (string) $uploadResponse->json('data.url');
        $path = parse_url($url, PHP_URL_PATH);

        $this->assertNotEmpty($path, 'Could not parse path from data.url.');

        $this->get($path)->assertOk();
    }
}
