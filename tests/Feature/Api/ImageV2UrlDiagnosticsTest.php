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

        $url = $uploadResponse->json('data.url');

        $this->assertNotEmpty($url, 'Missing data.url in /api/v2/images/upload response.');
        $this->assertTrue(str_starts_with($url, 'http'), 'Expected data.url to be an absolute URL.');
    }

    public function test_v2_upload_url_is_reachable_via_get_request(): void
    {
        $uploadResponse = $this->post('/api/v2/images/upload', [
            'image' => UploadedFile::fake()->image('v2-reachable.png', 32, 32),
            'name' => 'V2 Reachability Image',
        ])->assertOk()->assertJsonPath('success', true);

        $url = (string) $uploadResponse->json('data.url');
        $this->assertNotEmpty($url, 'Could not parse data.url.');
    }
}
