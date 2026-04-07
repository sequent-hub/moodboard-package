<?php

namespace Futurello\MoodBoard\Tests\Feature\Api;

use Futurello\MoodBoard\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

class FileApiContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_upload_returns_url_only_contract_for_file_objects(): void
    {
        $upload = $this->post('/api/v2/files/upload', [
            'file' => \Illuminate\Http\UploadedFile::fake()->create('sample.txt', 10, 'text/plain'),
            'name' => 'Sample',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Sample')
            ->assertJsonPath('data.mime_type', 'text/plain');

        $this->assertNotEmpty($upload->json('data.url'));
        $this->assertNull($upload->json('data.id'));
    }

    public function test_upload_requires_file_payload(): void
    {
        $this->postJson('/api/v2/files/upload', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_file_id_based_routes_are_not_available_in_v2_runtime(): void
    {
        $this->getJson('/api/v2/files/not-existing-id')
            ->assertStatus(404);
    }
}
