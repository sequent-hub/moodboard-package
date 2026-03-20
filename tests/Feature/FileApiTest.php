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

    public function test_it_uploads_updates_downloads_and_deletes_file(): void
    {
        $uploadResponse = $this->post('/api/files/upload', [
            'file' => UploadedFile::fake()->create('sample.txt', 20, 'text/plain'),
            'name' => 'Sample file',
        ])->assertOk()->assertJsonPath('success', true);

        $fileId = $uploadResponse->json('data.id');
        $this->assertNotEmpty($fileId);

        $this->getJson("/api/files/{$fileId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $fileId)
            ->assertJsonPath('data.name', 'Sample file');

        $this->putJson("/api/files/{$fileId}", [
            'name' => 'Renamed file',
        ])->assertOk()->assertJsonPath('success', true);

        $this->get("/api/files/{$fileId}/download")
            ->assertOk();

        $this->deleteJson("/api/files/{$fileId}")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_it_validates_upload_request(): void
    {
        $this->postJson('/api/files/upload', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
