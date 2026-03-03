<?php

namespace Futurello\MoodBoard\Tests\Feature;

use Futurello\MoodBoard\Tests\TestCase;

class MoodBoardRotationPersistenceTest extends TestCase
{
    public function test_it_persists_object_transform_rotation_after_save_and_load(): void
    {
        $this->postJson('/api/moodboard/save', [
            'boardId' => 'board-rotation',
            'boardData' => [
                'name' => 'Rotation board',
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
        ])->assertOk()->assertJsonPath('success', true);

        $this->getJson('/api/moodboard/load/board-rotation')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.objects.0.id', 'obj-rotation')
            ->assertJsonPath('data.objects.0.transform.pivotCompensated', false)
            ->assertJsonPath('data.objects.0.transform.rotation', 45);
    }
}
