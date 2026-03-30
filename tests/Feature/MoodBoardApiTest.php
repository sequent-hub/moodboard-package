<?php

namespace Futurello\MoodBoard\Tests\Feature;

use Futurello\MoodBoard\Tests\TestCase;

class MoodBoardApiTest extends TestCase
{
    public function test_it_saves_metadata_history_and_loads_board_data(): void
    {
        $boardId = 'board-main-flow';

        $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => $boardId,
            'name' => 'Main board',
            'settings' => [
                'backgroundColor' => '#ffffff',
            ],
        ])->assertOk()->assertJsonPath('success', true);

        $payload = [
            'moodboardId' => $boardId,
            'state' => [
                'objects' => [
                    [
                        'id' => 'obj-1',
                        'type' => 'note',
                        'position' => ['x' => 10, 'y' => 20],
                        'properties' => ['content' => 'hello'],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/v2/moodboard/history/save', $payload)
            ->assertOk()
            ->assertJsonPath('historyVersion', 1);

        $this->getJson('/api/v2/moodboard/board-main-flow')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.moodboardId', 'board-main-flow')
            ->assertJsonPath('data.state.objects.0.id', 'obj-1')
            ->assertJsonPath('data.settings.backgroundColor', '#ffffff');
    }

    public function test_it_returns_404_on_load_when_board_missing(): void
    {
        $this->getJson('/api/v2/moodboard/new-board-id')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_it_returns_not_implemented_for_compatibility_routes_on_v2(): void
    {
        $this->getJson('/api/v2/moodboard/list')
            ->assertStatus(501)
            ->assertJsonPath('success', false);

        $this->getJson('/api/v2/moodboard/show/board-crud')
            ->assertStatus(501)
            ->assertJsonPath('success', false);

        $this->postJson('/api/v2/moodboard/duplicate/board-crud')
            ->assertStatus(501)
            ->assertJsonPath('success', false);

        $this->deleteJson('/api/v2/moodboard/delete/board-crud')
            ->assertStatus(501)
            ->assertJsonPath('success', false);
    }

    public function test_it_returns_not_implemented_for_stats_route_on_v2(): void
    {
        $this->getJson('/api/v2/moodboard/board-stats/images/stats')
            ->assertStatus(501)
            ->assertJsonPath('success', false);
    }
}
