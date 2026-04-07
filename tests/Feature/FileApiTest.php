<?php

namespace Futurello\MoodBoard\Tests\Feature;

use Futurello\MoodBoard\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class FileApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_it_uploads_file_on_v2_and_returns_url_without_id_contract(): void
    {
        $uploadResponse = $this->post('/api/v2/files/upload', [
            'file' => UploadedFile::fake()->create('sample.txt', 20, 'text/plain'),
            'name' => 'Sample file',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Sample file')
            ->assertJsonPath('data.mime_type', 'text/plain');

        $this->assertNotEmpty($uploadResponse->json('data.url'));
        $this->assertNull($uploadResponse->json('data.id'));
    }

    public function test_it_does_not_expose_file_id_routes_in_v2_runtime(): void
    {
        $this->getJson('/api/v2/files/legacy-id')
            ->assertStatus(404);

        $this->get('/api/v2/files/legacy-id/download')
            ->assertStatus(404);
    }

    public function test_it_validates_upload_request(): void
    {
        $this->postJson('/api/v2/files/upload', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
