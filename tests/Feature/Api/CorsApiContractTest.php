<?php

namespace Futurello\MoodBoard\Tests\Feature\Api;

use Futurello\MoodBoard\Tests\TestCase;
use Illuminate\Http\UploadedFile;

class CorsApiContractTest extends TestCase
{
    public function test_it_handles_options_for_v2_moodboard_and_images_routes(): void
    {
        $this->call('OPTIONS', '/api/v2/moodboard/metadata/save')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*');

        $this->call('OPTIONS', '/api/v2/images/upload')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_it_includes_cors_headers_on_regular_v2_api_responses(): void
    {
        $saveResponse = $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => 'cors-headers-board',
            'name' => 'CORS Board',
            'settings' => ['backgroundColor' => '#ffffff'],
        ])->assertOk();

        $saveResponse->assertHeader('Access-Control-Allow-Origin', '*');

        $uploadResponse = $this->post('/api/v2/images/upload', [
            'image' => UploadedFile::fake()->image('cors.png', 10, 10),
            'name' => 'CORS Image',
        ])->assertOk();

        $uploadResponse->assertHeader('Access-Control-Allow-Origin', '*');
    }
}
