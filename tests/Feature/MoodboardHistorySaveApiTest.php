<?php

namespace Futurello\MoodBoard\Tests\Feature;

use Futurello\MoodBoard\Models\MoodboardHistory;
use Futurello\MoodBoard\Tests\TestCase;

class MoodboardHistorySaveApiTest extends TestCase
{
    public function test_history_save_requires_moodboard_id_and_state(): void
    {
        $this->postJson('/api/moodboard/history/save', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_history_save_creates_first_version(): void
    {
        $response = $this->postJson('/api/moodboard/history/save', [
            'moodboardId' => 'mb-history-v1',
            'state' => [
                'objects' => [
                    ['id' => 'n1', 'type' => 'note', 'properties' => ['content' => 'v1']],
                ],
                'settings' => ['backgroundColor' => '#ffffff'],
            ],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('deduplicated', false)
            ->assertJsonPath('historyVersion', 1);

        $this->assertSame('mb-history-v1', $response->json('moodboardId'));
        $this->assertDatabaseHas('moodboard_history', [
            'moodboard_id' => 'mb-history-v1',
            'version' => 1,
        ]);
    }

    public function test_history_save_deduplicates_identical_state(): void
    {
        $payload = [
            'moodboardId' => 'mb-history-dedup',
            'state' => [
                'objects' => [
                    ['id' => 'same-note', 'type' => 'note', 'properties' => ['content' => 'same']],
                ],
                'settings' => ['backgroundColor' => '#eeeeee'],
            ],
        ];

        $this->postJson('/api/moodboard/history/save', $payload)
            ->assertOk()
            ->assertJsonPath('historyVersion', 1)
            ->assertJsonPath('deduplicated', false);

        $this->postJson('/api/moodboard/history/save', $payload)
            ->assertOk()
            ->assertJsonPath('historyVersion', 1)
            ->assertJsonPath('deduplicated', true);

        $count = MoodboardHistory::query()
            ->where('moodboard_id', 'mb-history-dedup')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_history_save_increments_version_for_changed_state(): void
    {
        $moodboardId = 'mb-history-increment';

        $this->postJson('/api/moodboard/history/save', [
            'moodboardId' => $moodboardId,
            'state' => [
                'objects' => [
                    ['id' => 'n1', 'type' => 'note', 'properties' => ['content' => 'v1']],
                ],
            ],
        ])->assertOk()->assertJsonPath('historyVersion', 1);

        $this->postJson('/api/moodboard/history/save', [
            'moodboardId' => $moodboardId,
            'state' => [
                'objects' => [
                    ['id' => 'n2', 'type' => 'note', 'properties' => ['content' => 'v2']],
                ],
            ],
        ])->assertOk()->assertJsonPath('historyVersion', 2);

        $versions = MoodboardHistory::query()
            ->where('moodboard_id', $moodboardId)
            ->orderBy('version')
            ->pluck('version')
            ->all();

        $this->assertSame([1, 2], $versions);
    }

    public function test_history_save_persists_action_type_and_created_by(): void
    {
        $this->postJson('/api/moodboard/history/save', [
            'moodboardId' => 'mb-history-metadata',
            'state' => ['objects' => []],
            'actionType' => 'undo',
            'createdBy' => 'user-42',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('moodboard_history', [
            'moodboard_id' => 'mb-history-metadata',
            'version' => 1,
            'action_type' => 'undo',
            'created_by' => 'user-42',
        ]);
    }
}
