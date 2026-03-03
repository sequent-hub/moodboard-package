<?php

namespace Futurello\MoodBoard\Tests\Feature;

use Futurello\MoodBoard\Tests\TestCase;

class MoodBoardApiTest extends TestCase
{
    public function test_it_saves_and_loads_board_data(): void
    {
        $payload = [
            'boardId' => 'board-main-flow',
            'boardData' => [
                'name' => 'Main board',
                'objects' => [
                    [
                        'id' => 'obj-1',
                        'type' => 'note',
                        'position' => ['x' => 10, 'y' => 20],
                        'properties' => ['content' => 'hello'],
                    ],
                ],
            ],
            'settings' => [
                'backgroundColor' => '#ffffff',
            ],
        ];

        $this->postJson('/api/moodboard/save', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/moodboard/load/board-main-flow')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', 'board-main-flow')
            ->assertJsonPath('data.objects.0.id', 'obj-1')
            ->assertJsonPath('data.settings.backgroundColor', '#ffffff');
    }

    public function test_it_creates_new_board_on_load_when_missing(): void
    {
        $this->getJson('/api/moodboard/load/new-board-id')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', 'new-board-id')
            ->assertJsonPath('data.objects', []);
    }

    public function test_it_lists_duplicates_and_deletes_board(): void
    {
        $this->postJson('/api/moodboard/save', [
            'boardId' => 'board-crud',
            'boardData' => [
                'name' => 'CRUD board',
                'objects' => [],
            ],
        ])->assertOk();

        $this->getJson('/api/moodboard/list')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['id' => 'board-crud']);

        $this->getJson('/api/moodboard/show/board-crud')
            ->assertOk()
            ->assertJsonPath('data.id', 'board-crud');

        $duplicate = $this->postJson('/api/moodboard/duplicate/board-crud')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.id');

        $this->assertNotSame('board-crud', $duplicate);

        $this->deleteJson('/api/moodboard/delete/board-crud')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/moodboard/show/board-crud')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_it_returns_object_type_statistics(): void
    {
        $this->postJson('/api/moodboard/save', [
            'boardId' => 'board-stats',
            'boardData' => [
                'name' => 'Stats board',
                'objects' => [
                    ['id' => 'n1', 'type' => 'note'],
                    ['id' => 'n2', 'type' => 'note'],
                    ['id' => 'i1', 'type' => 'image'],
                ],
            ],
        ])->assertOk();

        $this->getJson('/api/moodboard/board-stats/images/stats')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.by_type.note', 2)
            ->assertJsonPath('data.by_type.image', 1);
    }
}
