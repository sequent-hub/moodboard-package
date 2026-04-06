<?php

namespace Futurello\MoodBoard\Tests\Feature\Api;

use Futurello\MoodBoard\Tests\TestCase;

class MoodBoardApiContractTest extends TestCase
{
    private ?string $previousCdnBaseUrl = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousCdnBaseUrl = getenv('MOODBOARD_IMAGE_CDN_BASE_URL') !== false
            ? (string) getenv('MOODBOARD_IMAGE_CDN_BASE_URL')
            : null;
    }

    protected function tearDown(): void
    {
        if ($this->previousCdnBaseUrl === null) {
            putenv('MOODBOARD_IMAGE_CDN_BASE_URL');
        } else {
            putenv('MOODBOARD_IMAGE_CDN_BASE_URL=' . $this->previousCdnBaseUrl);
        }

        parent::tearDown();
    }

    public function test_it_returns_latest_history_for_short_v2_route(): void
    {
        $boardId = 'contract-board-load-short';

        $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => $boardId,
            'name' => 'Contract board',
            'settings' => ['backgroundColor' => '#ffffff'],
        ])->assertOk()->assertJsonPath('success', true);

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $boardId,
            'state' => [
                'objects' => [
                    [
                        'id' => 'note-contract-1',
                        'type' => 'note',
                        'position' => ['x' => 5, 'y' => 7],
                        'properties' => ['content' => 'api contract'],
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('historyVersion', 1);

        $this->getJson("/api/v2/moodboard/{$boardId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.moodboardId', $boardId)
            ->assertJsonPath('data.state.objects.0.id', 'note-contract-1')
            ->assertJsonPath('data.state.objects.0.properties.content', 'api contract');

        $this->getJson("/api/v2/moodboard/load/{$boardId}")
            ->assertStatus(501)
            ->assertJsonPath('success', false);
    }

    public function test_it_validates_metadata_and_history_payload_types(): void
    {
        $this->postJson('/api/v2/moodboard/metadata/save', [
            'name' => 'Missing moodboardId',
            'settings' => ['backgroundColor' => '#ffffff'],
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => 'board-invalid-settings',
            'settings' => 'not-an-array',
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => 'board-invalid-history',
            'state' => 'not-an-array',
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_it_returns_501_for_compatibility_routes_in_v2(): void
    {
        $boardId = 'missing-board-contract';

        $this->getJson("/api/v2/moodboard/show/{$boardId}")
            ->assertStatus(501)
            ->assertJsonPath('success', false);

        $this->deleteJson("/api/v2/moodboard/delete/{$boardId}")
            ->assertStatus(501)
            ->assertJsonPath('success', false);

        $this->postJson("/api/v2/moodboard/duplicate/{$boardId}")
            ->assertStatus(501)
            ->assertJsonPath('success', false);

        $this->getJson("/api/v2/moodboard/{$boardId}/images/stats")
            ->assertStatus(501)
            ->assertJsonPath('success', false);
    }

    public function test_it_keeps_src_for_image_object_on_save_and_load(): void
    {
        $upload = $this->post('/api/v2/images/upload', [
            'image' => $this->fakeTinyPngUpload('contract-image.png'),
            'name' => 'Contract image',
        ])->assertOk();
        $src = $upload->json('data.url');
        $this->assertNotEmpty($src);

        $boardId = 'board-image-contract';
        $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => $boardId,
            'name' => 'Image contract board',
            'settings' => ['backgroundColor' => '#ffffff'],
        ])->assertOk()->assertJsonPath('success', true);

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $boardId,
            'state' => [
                'objects' => [
                    [
                        'id' => 'img-obj-contract',
                        'type' => 'image',
                        'src' => $src,
                        'position' => ['x' => 1, 'y' => 2],
                        'width' => 1,
                        'height' => 1,
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('historyVersion', 1);

        $loadedObject = $this->getJson("/api/v2/moodboard/{$boardId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.state.objects.0');

        $this->assertSame($src, $loadedObject['src'] ?? null);
    }

    public function test_it_normalizes_image_src_to_cdn_during_history_save_when_configured(): void
    {
        putenv('MOODBOARD_IMAGE_CDN_BASE_URL=https://cdn.example.com');

        $boardId = 'board-image-cdn-normalization';
        $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => $boardId,
            'name' => 'CDN normalization board',
            'settings' => ['backgroundColor' => '#ffffff'],
        ])->assertOk()->assertJsonPath('success', true);

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $boardId,
            'state' => [
                'objects' => [
                    [
                        'id' => 'img-obj-cdn',
                        'type' => 'image',
                        'src' => 'http://localhost/storage/s3/images/2026/04/test.png',
                        'position' => ['x' => 10, 'y' => 20],
                        'width' => 100,
                        'height' => 80,
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('historyVersion', 1);

        $loadedObject = $this->getJson("/api/v2/moodboard/{$boardId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.state.objects.0');

        $this->assertSame(
            'https://cdn.example.com/images/2026/04/test.png',
            $loadedObject['src'] ?? null
        );
    }

    private function fakeTinyPngUpload(string $filename)
    {
        return \Illuminate\Http\UploadedFile::fake()->image($filename, 1, 1);
    }
}
