<?php

namespace Futurello\MoodBoard\Tests\Feature;

use Futurello\MoodBoard\Models\MoodboardHistory;
use Futurello\MoodBoard\Tests\TestCase;
use Illuminate\Support\Facades\Log;

/**
 * Regression tests for optimistic concurrency (baseVersion) and soft anti-rollback (warning log).
 *
 * (a) baseVersion < latest  → HTTP 409, no new snapshot written
 * (b) baseVersion == latest → HTTP 200, version incremented
 * (c) no baseVersion        → HTTP 200, legacy behaviour unchanged
 * (d) strict-subset sharp drop → HTTP 200 (snapshot written), Log::warning emitted
 */
class MoodboardHistoryConcurrencyTest extends TestCase
{
    // -------------------------------------------------------------------------
    // (a) stale baseVersion is rejected with 409
    // -------------------------------------------------------------------------

    public function test_stale_base_version_returns_409_and_does_not_write_snapshot(): void
    {
        $id = 'mb-concurrency-stale';

        // Seed two versions so the current tip is version 2.
        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $id,
            'state' => ['objects' => [['id' => 'obj-a', 'type' => 'note']]],
        ])->assertOk()->assertJsonPath('historyVersion', 1);

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $id,
            'state' => ['objects' => [['id' => 'obj-b', 'type' => 'note']]],
        ])->assertOk()->assertJsonPath('historyVersion', 2);

        // Client still thinks it is at version 1 — should be rejected.
        $response = $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $id,
            'state' => ['objects' => [['id' => 'obj-c', 'type' => 'note']]],
            'baseVersion' => 1,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'stale_base_version')
            ->assertJsonPath('currentVersion', 2);

        // Version 3 must NOT have been created.
        $this->assertDatabaseMissing('moodboard_history', [
            'moodboard_id' => $id,
            'version' => 3,
        ]);

        $count = MoodboardHistory::query()
            ->where('moodboard_id', $id)
            ->count();
        $this->assertSame(2, $count);
    }

    // -------------------------------------------------------------------------
    // (b) baseVersion == latest → success, version incremented
    // -------------------------------------------------------------------------

    public function test_matching_base_version_allows_write_and_increments_version(): void
    {
        $id = 'mb-concurrency-match';

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $id,
            'state' => ['objects' => [['id' => 'obj-1', 'type' => 'note']]],
        ])->assertOk()->assertJsonPath('historyVersion', 1);

        // Send the next snapshot with baseVersion correctly pointing to the current tip.
        $response = $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $id,
            'state' => ['objects' => [['id' => 'obj-2', 'type' => 'note']]],
            'baseVersion' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('deduplicated', false)
            ->assertJsonPath('historyVersion', 2);

        $this->assertDatabaseHas('moodboard_history', [
            'moodboard_id' => $id,
            'version' => 2,
        ]);
    }

    // -------------------------------------------------------------------------
    // (c) absent baseVersion → legacy behaviour, always succeeds
    // -------------------------------------------------------------------------

    public function test_absent_base_version_saves_without_concurrency_check(): void
    {
        $id = 'mb-concurrency-legacy';

        // First snapshot.
        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $id,
            'state' => ['objects' => [['id' => 'obj-x', 'type' => 'note']]],
        ])->assertOk()->assertJsonPath('historyVersion', 1);

        // Second snapshot without baseVersion — must succeed regardless.
        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $id,
            'state' => ['objects' => [['id' => 'obj-y', 'type' => 'note']]],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('historyVersion', 2);

        $this->assertDatabaseHas('moodboard_history', [
            'moodboard_id' => $id,
            'version' => 2,
        ]);
    }

    // -------------------------------------------------------------------------
    // (d) strict-subset sharp drop → warning logged, snapshot still written
    // -------------------------------------------------------------------------

    public function test_sharp_subset_drop_logs_warning_but_still_saves_snapshot(): void
    {
        $id = 'mb-concurrency-antirollback';

        // Version 1: seven objects.
        $prevObjects = [];
        for ($i = 1; $i <= 7; $i++) {
            $prevObjects[] = ['id' => "obj-{$i}", 'type' => 'note'];
        }

        // Set up log expectations before any request so the mock captures calls from both.
        // normalizeStateForStorage emits Log::info() on every history save call (two requests).
        Log::shouldReceive('info')->twice();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($id): bool {
                return str_contains($message, 'anti-rollback')
                    && ($context['moodboard_id'] ?? '') === $id
                    && ($context['prev_count'] ?? 0) === 7
                    && ($context['next_count'] ?? 0) === 1;
            });

        // Request 1: seed version 1.
        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $id,
            'state' => ['objects' => $prevObjects],
        ])->assertOk()->assertJsonPath('historyVersion', 1);

        // Request 2: only one object — 6 out of 7 removed (≈86%), strict subset.
        $response = $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $id,
            'state' => ['objects' => [['id' => 'obj-1', 'type' => 'note']]],
        ]);

        // Snapshot must have been saved despite the warning.
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('historyVersion', 2);

        $this->assertDatabaseHas('moodboard_history', [
            'moodboard_id' => $id,
            'version' => 2,
        ]);
    }
}
