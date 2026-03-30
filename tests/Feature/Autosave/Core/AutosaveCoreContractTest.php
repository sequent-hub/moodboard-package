<?php

namespace Futurello\MoodBoard\Tests\Feature\Autosave\Core;

use Futurello\MoodBoard\Tests\Feature\Autosave\Support\AbstractAutosaveTestCase;

class AutosaveCoreContractTest extends AbstractAutosaveTestCase
{
    public function test_it_requires_moodboard_id_for_metadata_save_request(): void
    {
        $this->postJson('/api/v2/moodboard/metadata/save', [
            'name' => 'missing id',
            'settings' => ['backgroundColor' => '#ffffff'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_it_returns_latest_history_state_for_same_moodboard_id(): void
    {
        $moodboardId = 'board-autosave-core';

        $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => $moodboardId,
            'name' => 'Core autosave board',
            'settings' => ['backgroundColor' => '#fafafa'],
        ])->assertOk()->assertJsonPath('success', true);

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $moodboardId,
            'state' => [
                'objects' => [
                    [
                        'id' => 'obj-v1',
                        'type' => 'note',
                        'position' => ['x' => 10, 'y' => 10],
                        'properties' => ['content' => 'v1'],
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('historyVersion', 1);

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $moodboardId,
            'state' => [
                'objects' => [
                    [
                        'id' => 'obj-v2',
                        'type' => 'note',
                        'position' => ['x' => 30, 'y' => 40],
                        'properties' => ['content' => 'v2'],
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('historyVersion', 2);

        $this->getJson("/api/v2/moodboard/{$moodboardId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.state.objects.0.id', 'obj-v2')
            ->assertJsonPath('data.state.objects.0.properties.content', 'v2');
    }
}
