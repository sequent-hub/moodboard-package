<?php

namespace Futurello\MoodBoard\Tests\Feature;

use Futurello\MoodBoard\Tests\TestCase;

/**
 * Regression tests по официальному контракту фронтенда.
 * Payload-ы соответствуют структуре из getBoardData() → ApiClient/SaveManager.
 */
class MoodBoardFrontendPayloadRegressionTest extends TestCase
{
    /** Записка с поворотом и текстом — точный формат фронта */
    public function test_it_persists_note_with_transform_rotation_from_frontend_payload(): void
    {
        $payload = [
            'boardId' => 'board_abc123',
            'boardData' => [
                'objects' => [
                    [
                        'id' => 'obj_17480001',
                        'type' => 'note',
                        'position' => ['x' => 150, 'y' => 300],
                        'width' => 250,
                        'height' => 250,
                        'properties' => [
                            'content' => 'Текст записки',
                            'fontSize' => 32,
                            'fontFamily' => 'Caveat, Arial, cursive',
                            'backgroundColor' => 16775620,
                            'borderColor' => 16361509,
                            'textColor' => 1710618,
                        ],
                        'transform' => [
                            'pivotCompensated' => false,
                            'rotation' => 45,
                        ],
                        'created' => '2026-03-02T15:00:00.000Z',
                    ],
                ],
                'name' => 'My Board',
                'description' => null,
            ],
            'settings' => [
                'backgroundColor' => '#F5F5F5',
                'grid' => ['type' => 'dot', 'size' => 20, 'visible' => true, 'color' => '#E0E0E0'],
                'zoom' => ['min' => 0.1, 'max' => 5.0, 'default' => 1.0, 'current' => 1.2],
                'pan' => ['x' => -100, 'y' => -50],
                'canvas' => ['width' => 1920, 'height' => 1080],
            ],
        ];

        $this->postJson('/api/moodboard/save', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/moodboard/load/board_abc123')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.objects.0.id', 'obj_17480001')
            ->assertJsonPath('data.objects.0.transform.pivotCompensated', false)
            ->assertJsonPath('data.objects.0.transform.rotation', 45)
            ->assertJsonPath('data.settings.backgroundColor', '#F5F5F5')
            ->assertJsonPath('data.settings.grid.type', 'dot');
    }

    /** Несколько объектов разных типов: текст, изображение, фрейм — с transform */
    public function test_it_persists_multiple_object_types_with_transform(): void
    {
        $payload = [
            'boardId' => 'board_multi_types',
            'boardData' => [
                'objects' => [
                    [
                        'id' => 'obj_17480002',
                        'type' => 'text',
                        'position' => ['x' => 400, 'y' => 100],
                        'width' => 200,
                        'height' => 50,
                        'properties' => [
                            'content' => 'Заголовок',
                            'fontSize' => 18,
                            'fontFamily' => 'Arial, sans-serif',
                        ],
                        'transform' => ['pivotCompensated' => false, 'rotation' => 0],
                        'created' => '2026-03-02T15:01:00.000Z',
                    ],
                    [
                        'id' => 'obj_17480003',
                        'type' => 'image',
                        'position' => ['x' => 600, 'y' => 200],
                        'width' => 300,
                        'height' => 200,
                        'properties' => ['name' => 'photo.jpg', 'width' => 300, 'height' => 200],
                        'imageId' => 'img_uuid_abc',
                        'transform' => ['pivotCompensated' => false],
                        'created' => '2026-03-02T15:02:00.000Z',
                    ],
                    [
                        'id' => 'obj_17480004',
                        'type' => 'frame',
                        'position' => ['x' => 50, 'y' => 50],
                        'width' => 800,
                        'height' => 600,
                        'properties' => ['title' => 'Фрейм 1', 'width' => 800, 'height' => 600],
                        'transform' => ['pivotCompensated' => false],
                        'created' => '2026-03-02T15:03:00.000Z',
                    ],
                ],
                'name' => 'Project Board',
                'description' => 'Описание доски',
            ],
            'settings' => [
                'backgroundColor' => '#FFFFFF',
                'zoom' => ['min' => 0.1, 'max' => 5.0, 'default' => 1.0, 'current' => 1.0],
                'pan' => ['x' => 0, 'y' => 0],
                'canvas' => ['width' => 1200, 'height' => 800],
            ],
        ];

        $this->postJson('/api/moodboard/save', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $resp = $this->getJson('/api/moodboard/load/board_multi_types')
            ->assertOk()
            ->assertJsonPath('success', true);

        $objects = $resp->json('data.objects');
        $this->assertCount(3, $objects);
        $this->assertEquals('obj_17480002', $objects[0]['id']);
        $this->assertArrayHasKey('transform', $objects[0]);
        $this->assertEquals(0, $objects[0]['transform']['rotation'] ?? 0);
        $this->assertEquals('obj_17480003', $objects[1]['id']);
        $this->assertArrayHasKey('transform', $objects[1]);
        $this->assertEquals('obj_17480004', $objects[2]['id']);
        $this->assertArrayHasKey('transform', $objects[2]);
    }

    /** Пустая доска (первое сохранение) */
    public function test_it_saves_empty_board_first_time(): void
    {
        $payload = [
            'boardId' => 'board_new',
            'boardData' => [
                'objects' => [],
                'name' => 'Untitled Board',
                'description' => null,
            ],
            'settings' => [
                'backgroundColor' => '#F5F5F5',
                'zoom' => ['min' => 0.1, 'max' => 5.0, 'default' => 1.0, 'current' => 1.0],
                'pan' => ['x' => 0, 'y' => 0],
                'canvas' => ['width' => 1920, 'height' => 1080],
            ],
        ];

        $this->postJson('/api/moodboard/save', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/moodboard/load/board_new')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.objects', [])
            ->assertJsonPath('data.settings.backgroundColor', '#F5F5F5');
    }

    /** transform без rotation (frame/image) — бэкенд отдаёт as-is, не фильтрует */
    public function test_it_preserves_transform_without_rotation_as_is(): void
    {
        $payload = [
            'boardId' => 'board_no_rotation',
            'boardData' => [
                'objects' => [
                    [
                        'id' => 'obj_frame',
                        'type' => 'frame',
                        'position' => ['x' => 10, 'y' => 20],
                        'width' => 100,
                        'height' => 100,
                        'properties' => ['title' => 'Frame'],
                        'transform' => ['pivotCompensated' => false],
                    ],
                ],
                'name' => 'Board',
                'description' => null,
            ],
            'settings' => ['backgroundColor' => '#fff', 'zoom' => ['default' => 1.0]],
        ];

        $this->postJson('/api/moodboard/save', $payload)->assertOk();

        $this->getJson('/api/moodboard/load/board_no_rotation')
            ->assertOk()
            ->assertJsonPath('data.objects.0.transform.pivotCompensated', false)
            ->assertJsonPath('data.objects.0.transform', ['pivotCompensated' => false]);
    }
}
