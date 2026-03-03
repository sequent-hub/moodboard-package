<?php

namespace Futurello\MoodBoard\Tests\Feature\Autosave\Core;

use Futurello\MoodBoard\Tests\Feature\Autosave\Support\AbstractAutosaveTestCase;

class AutosaveCoreContractTest extends AbstractAutosaveTestCase
{
    public function test_it_requires_board_id_for_save_request(): void
    {
        $this->postJson('/api/moodboard/save', [
            'boardData' => ['objects' => []],
        ])->assertStatus(422);
    }

    public function test_it_returns_latest_saved_state_for_same_board_id(): void
    {
        $boardId = 'board-autosave-core';

        $this->postJson('/api/moodboard/save', [
            'boardId' => $boardId,
            'boardData' => [
                'name' => 'Core autosave board',
                'objects' => [
                    [
                        'id' => 'obj-v1',
                        'type' => 'note',
                        'position' => ['x' => 10, 'y' => 10],
                        'properties' => ['content' => 'v1'],
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        $this->postJson('/api/moodboard/save', [
            'boardId' => $boardId,
            'boardData' => [
                'name' => 'Core autosave board',
                'objects' => [
                    [
                        'id' => 'obj-v2',
                        'type' => 'note',
                        'position' => ['x' => 30, 'y' => 40],
                        'properties' => ['content' => 'v2'],
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        $this->getJson("/api/moodboard/{$boardId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.objects.0.id', 'obj-v2')
            ->assertJsonPath('data.objects.0.properties.content', 'v2');
    }
}
