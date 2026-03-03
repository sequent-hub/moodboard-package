<?php

namespace Futurello\MoodBoard\Tests\Feature\Api;

use Futurello\MoodBoard\Tests\TestCase;
use Illuminate\Http\UploadedFile;

class CorsApiContractTest extends TestCase
{
    public function test_it_handles_options_for_moodboard_and_images_routes(): void
    {
        $this->call('OPTIONS', '/api/moodboard/anything')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');

        $this->call('OPTIONS', '/api/images/anything')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
    }

    public function test_it_includes_cors_headers_on_regular_api_responses(): void
    {
        $saveResponse = $this->postJson('/api/moodboard/save', [
            'boardId' => 'cors-headers-board',
            'boardData' => ['objects' => []],
        ])->assertOk();

        $saveResponse->assertHeader('Access-Control-Allow-Origin', '*');

        $uploadResponse = $this->post('/api/images/upload', [
            'image' => UploadedFile::fake()->image('cors.png', 10, 10),
            'name' => 'CORS Image',
        ])->assertOk();

        $uploadResponse->assertHeader('Access-Control-Allow-Origin', '*');
    }
}
