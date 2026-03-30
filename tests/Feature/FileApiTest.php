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

    public function test_it_uploads_reads_and_downloads_file_on_v2(): void
    {
        $uploadResponse = $this->post('/api/v2/files/upload', [
            'file' => UploadedFile::fake()->create('sample.txt', 20, 'text/plain'),
            'name' => 'Sample file',
        ])->assertOk()->assertJsonPath('success', true);

        $fileId = $uploadResponse->json('data.id');
        $this->assertNotEmpty($fileId);

        $this->getJson("/api/v2/files/{$fileId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $fileId)
            ->assertJsonPath('data.name', 'Sample file');

        $this->get("/api/v2/files/{$fileId}/download")
            ->assertOk();
    }

    public function test_it_returns_not_implemented_for_update_and_delete_on_v2(): void
    {
        $uploadResponse = $this->post('/api/v2/files/upload', [
            'file' => UploadedFile::fake()->create('sample.txt', 20, 'text/plain'),
            'name' => 'Sample file',
        ])->assertOk()->assertJsonPath('success', true);

        $fileId = $uploadResponse->json('data.id');
        $this->assertNotEmpty($fileId);

        $this->putJson("/api/v2/files/{$fileId}", [
            'name' => 'Renamed file',
        ])->assertStatus(501)->assertJsonPath('success', false);

        $this->deleteJson("/api/v2/files/{$fileId}")
            ->assertStatus(501)
            ->assertJsonPath('success', false);
    }

    public function test_it_validates_upload_request(): void
    {
        $this->postJson('/api/v2/files/upload', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
