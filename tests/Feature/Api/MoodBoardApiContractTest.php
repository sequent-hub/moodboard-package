<?php

namespace Futurello\MoodBoard\Tests\Feature\Api;

use Futurello\MoodBoard\Tests\TestCase;

class MoodBoardApiContractTest extends TestCase
{
    public function test_it_returns_same_board_data_for_load_and_short_routes(): void
    {
        $boardId = 'contract-board-load-short';
        $payload = [
            'boardId' => $boardId,
            'boardData' => [
                'name' => 'Contract board',
                'objects' => [
                    [
                        'id' => 'note-contract-1',
                        'type' => 'note',
                        'position' => ['x' => 5, 'y' => 7],
                        'properties' => ['content' => 'api contract'],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/moodboard/save', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $loadRoute = $this->getJson("/api/moodboard/load/{$boardId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data');

        $shortRoute = $this->getJson("/api/moodboard/{$boardId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data');

        $this->assertSame($loadRoute['id'], $shortRoute['id']);
        $this->assertSame($loadRoute['objects'], $shortRoute['objects']);
    }

    public function test_it_validates_board_data_and_settings_types(): void
    {
        $this->postJson('/api/moodboard/save', [
            'boardId' => 'board-invalid-boarddata',
            'boardData' => 'not-an-array',
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->postJson('/api/moodboard/save', [
            'boardId' => 'board-invalid-settings',
            'boardData' => ['objects' => []],
            'settings' => 'not-an-array',
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_it_returns_404_for_missing_board_in_show_delete_duplicate_and_stats(): void
    {
        $boardId = 'missing-board-contract';

        $this->getJson("/api/moodboard/show/{$boardId}")
            ->assertStatus(404)
            ->assertJsonPath('success', false);

        $this->deleteJson("/api/moodboard/delete/{$boardId}")
            ->assertStatus(404)
            ->assertJsonPath('success', false);

        $this->postJson("/api/moodboard/duplicate/{$boardId}")
            ->assertStatus(404)
            ->assertJsonPath('success', false);

        $this->getJson("/api/moodboard/{$boardId}/images/stats")
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_it_drops_src_when_image_id_is_present_on_save_and_restores_src_on_load(): void
    {
        $upload = $this->post('/api/images/upload', [
            'image' => $this->fakeTinyPngUpload('contract-image.png'),
            'name' => 'Contract image',
        ])->assertOk();
        $imageId = $upload->json('data.imageId');
        $this->assertNotEmpty($imageId);

        $boardId = 'board-image-contract';
        $this->postJson('/api/moodboard/save', [
            'boardId' => $boardId,
            'boardData' => [
                'name' => 'Image contract board',
                'objects' => [
                    [
                        'id' => 'img-obj-contract',
                        'type' => 'image',
                        'imageId' => $imageId,
                        'src' => 'data:image/png;base64,AAA',
                        'position' => ['x' => 1, 'y' => 2],
                        'width' => 1,
                        'height' => 1,
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        $loadedObject = $this->getJson("/api/moodboard/{$boardId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.objects.0');

        $this->assertSame($imageId, $loadedObject['imageId'] ?? null);
        $this->assertNotEmpty($loadedObject['src'] ?? null);
    }

    private function fakeTinyPngUpload(string $filename)
    {
        return \Illuminate\Http\UploadedFile::fake()->image($filename, 1, 1);
    }
}
