<?php

namespace Futurello\MoodBoard\Tests\Feature;

use Futurello\MoodBoard\Tests\TestCase;

class MoodBoardFrontendPayloadRegressionTest extends TestCase
{
    public function test_it_keeps_transform_for_multiple_object_types_from_frontend_payload(): void
    {
        $payload = [
            'boardId' => 'board-frontend-transform',
            'boardData' => [
                'name' => 'Frontend board',
                'objects' => [
                    [
                        'id' => 'note-1',
                        'type' => 'note',
                        'position' => ['x' => 100, 'y' => 200],
                        'width' => 250,
                        'height' => 250,
                        'properties' => ['content' => 'Note'],
                        'transform' => ['pivotCompensated' => false, 'rotation' => 45],
                    ],
                    [
                        'id' => 'text-1',
                        'type' => 'text',
                        'position' => ['x' => 300, 'y' => 220],
                        'properties' => ['content' => 'Text'],
                        'transform' => ['pivotCompensated' => false, 'rotation' => 90],
                    ],
                    [
                        'id' => 'shape-1',
                        'type' => 'shape',
                        'position' => ['x' => 400, 'y' => 260],
                        'properties' => ['shapeType' => 'rectangle'],
                        'transform' => ['pivotCompensated' => true, 'rotation' => 30],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/moodboard/save', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/moodboard/load/board-frontend-transform')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.objects.0.transform.rotation', 45)
            ->assertJsonPath('data.objects.1.transform.rotation', 90)
            ->assertJsonPath('data.objects.2.transform.rotation', 30);
    }

    public function test_it_persists_settings_when_frontend_sends_them_inside_board_data(): void
    {
        $this->postJson('/api/moodboard/save', [
            'boardId' => 'board-frontend-settings',
            'boardData' => [
                'name' => 'Board with nested settings',
                'objects' => [],
                'settings' => [
                    'backgroundColor' => '#123456',
                    'grid' => ['type' => 'dot'],
                    'zoom' => ['default' => 1.25],
                ],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        $this->getJson('/api/moodboard/load/board-frontend-settings')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.settings.backgroundColor', '#123456')
            ->assertJsonPath('data.settings.grid.type', 'dot')
            ->assertJsonPath('data.settings.zoom.default', 1.25);
    }
}
