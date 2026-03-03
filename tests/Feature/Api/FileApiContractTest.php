<?php

namespace Futurello\MoodBoard\Tests\Feature\Api;

use Futurello\MoodBoard\Models\File as StoredFile;
use Futurello\MoodBoard\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

class FileApiContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_it_returns_404_for_non_existing_file_on_show(): void
    {
        $this->getJson('/api/files/not-existing-id')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_it_returns_validation_error_on_invalid_update_payload(): void
    {
        $upload = $this->post('/api/files/upload', [
            'file' => \Illuminate\Http\UploadedFile::fake()->create('sample.txt', 10, 'text/plain'),
            'name' => 'Sample',
        ])->assertOk();

        $id = $upload->json('data.id');
        $this->assertNotEmpty($id);

        $this->putJson("/api/files/{$id}", [
            'name' => str_repeat('x', 300),
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_it_returns_500_for_non_existing_file_on_update_download_and_delete(): void
    {
        $this->putJson('/api/files/not-existing-id', [
            'name' => 'X',
        ])->assertStatus(500)->assertJsonPath('success', false);

        $this->get('/api/files/not-existing-id/download')
            ->assertStatus(500)
            ->assertJsonPath('success', false);

        $this->deleteJson('/api/files/not-existing-id')
            ->assertStatus(500)
            ->assertJsonPath('success', false);
    }

    public function test_it_returns_404_when_file_record_exists_but_physical_file_is_missing(): void
    {
        $file = StoredFile::create([
            'name' => 'missing',
            'filename' => 'missing.txt',
            'path' => 'files/missing.txt',
            'mime_type' => 'text/plain',
            'size' => 1,
            'extension' => 'txt',
            'hash' => 'missing-file-hash',
        ]);

        $this->get("/api/files/{$file->id}/download")
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_it_deduplicates_uploaded_files_with_same_content_hash(): void
    {
        $sameContent = 'same-content-for-dedup';

        $first = \Illuminate\Http\UploadedFile::fake()->createWithContent('one.txt', $sameContent);
        $second = \Illuminate\Http\UploadedFile::fake()->createWithContent('two.txt', $sameContent);

        $firstResp = $this->post('/api/files/upload', ['file' => $first, 'name' => 'One'])
            ->assertOk()
            ->assertJsonPath('success', true);
        $secondResp = $this->post('/api/files/upload', ['file' => $second, 'name' => 'Two'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(
            $firstResp->json('data.id'),
            $secondResp->json('data.id')
        );
    }
}
