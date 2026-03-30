<?php

namespace Futurello\MoodBoard\Tests\Feature;

use Futurello\MoodBoard\Tests\TestCase;

class MoodBoardRotationPersistenceTest extends TestCase
{
    public function test_it_persists_object_transform_rotation_after_save_and_load(): void
    {
        $boardId = 'board-rotation';

        $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => $boardId,
            'name' => 'Rotation board',
            'settings' => ['backgroundColor' => '#ffffff'],
        ])->assertOk()->assertJsonPath('success', true);

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $boardId,
            'state' => [
                'objects' => [
                    [
                        'id' => 'obj-rotation',
                        'type' => 'note',
                        'position' => ['x' => 100, 'y' => 200],
                        'width' => 250,
                        'height' => 250,
                        'properties' => [
                            'content' => 'Текст записки',
                            'backgroundColor' => 16775620,
                        ],
                        'transform' => [
                            'pivotCompensated' => false,
                            'rotation' => 45,
                        ],
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('historyVersion', 1);

        $this->getJson('/api/v2/moodboard/board-rotation')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.state.objects.0.id', 'obj-rotation')
            ->assertJsonPath('data.state.objects.0.transform.pivotCompensated', false)
            ->assertJsonPath('data.state.objects.0.transform.rotation', 45);
    }
}
