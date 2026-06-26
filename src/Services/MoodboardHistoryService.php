<?php

namespace Futurello\MoodBoard\Services;

use Futurello\MoodBoard\Models\MoodBoard;
use Futurello\MoodBoard\Models\MoodboardHistory;
use Futurello\MoodBoard\Repositories\MoodboardHistoryRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MoodboardHistoryService
{
    /**
     * Minimum fraction of objects that must be removed to trigger the anti-rollback warning.
     * A value of 0.5 means "more than half of previous objects were removed".
     */
    private const ANTI_ROLLBACK_FRACTION_THRESHOLD = 0.5;

    /**
     * Minimum absolute number of removed objects to trigger the anti-rollback warning.
     * Prevents noisy logging on small boards (e.g. 2 → 0).
     */
    private const ANTI_ROLLBACK_COUNT_THRESHOLD = 3;

    public function __construct(
        private readonly MoodboardHistoryRepository $historyRepository
    ) {}

    /**
     * @return array{deduplicated: bool, historyVersion: int}
     *                                                        | array{stale: true, currentVersion: int}
     */
    public function saveSnapshot(
        string $moodboardId,
        array $state,
        string $actionType = 'command_execute',
        ?string $createdBy = null,
        ?int $baseVersion = null
    ): array {
        return DB::transaction(function () use ($moodboardId, $state, $actionType, $createdBy, $baseVersion): array {
            // История — источник правды для контента, но moodboardLoad отдаёт 404,
            // если нет родительской строки moodboards. metadata/save создаёт её
            // отдельным запросом, чей сбой фронт намеренно игнорирует, поэтому при
            // его 422 (пустые settings и т.п.) доска сохраняется в историю, но не
            // загружается. Гарантируем родителя здесь, чтобы загрузка не терялась.
            $this->ensureMoodboardExists($moodboardId);

            $stateHash = $this->buildStateHash($state);
            $latest = $this->historyRepository->findLatestByMoodboardId($moodboardId, true);

            // Optimistic concurrency: reject if the caller's base is behind the current tip.
            if ($baseVersion !== null && $latest !== null && $baseVersion < (int) $latest->version) {
                return [
                    'stale' => true,
                    'currentVersion' => (int) $latest->version,
                ];
            }

            if ($latest && hash_equals($latest->state_hash, $stateHash)) {
                return [
                    'deduplicated' => true,
                    'historyVersion' => (int) $latest->version,
                ];
            }

            // Soft anti-rollback: log a warning when the new snapshot looks like an accidental undo
            // of many objects. Does NOT block the write — legitimate mass-deletes must still succeed.
            if ($latest !== null) {
                $this->logAntiRollbackWarningIfNeeded($moodboardId, $latest, $state);
            }

            $nextVersion = $latest ? ((int) $latest->version + 1) : 1;

            $this->historyRepository->append(
                $moodboardId,
                $nextVersion,
                $state,
                $stateHash,
                $actionType,
                now(),
                $createdBy
            );

            return [
                'deduplicated' => false,
                'historyVersion' => $nextVersion,
            ];
        });
    }

    /**
     * Emit a warning when new object IDs are a strict subset of the previous version's IDs
     * AND the object count drops sharply, which is a signal of an accidental rollback.
     */
    private function logAntiRollbackWarningIfNeeded(
        string $moodboardId,
        MoodboardHistory $latest,
        array $newState
    ): void {
        $prevObjects = $latest->state_json['objects'] ?? [];
        $newObjects = $newState['objects'] ?? [];

        if (! is_array($prevObjects) || ! is_array($newObjects)) {
            return;
        }

        $prevIds = array_values(array_filter(
            array_column($prevObjects, 'id'),
            static fn ($id) => is_string($id) && $id !== ''
        ));

        $newIds = array_values(array_filter(
            array_column($newObjects, 'id'),
            static fn ($id) => is_string($id) && $id !== ''
        ));

        $prevCount = count($prevIds);

        if ($prevCount === 0) {
            return;
        }

        $newCount = count($newIds);
        $removedCount = $prevCount - $newCount;

        if ($removedCount < self::ANTI_ROLLBACK_COUNT_THRESHOLD) {
            return;
        }

        if (($removedCount / $prevCount) <= self::ANTI_ROLLBACK_FRACTION_THRESHOLD) {
            return;
        }

        // Check that new IDs are a strict subset of prev IDs (no genuinely new objects added).
        $prevIdsSet = array_flip($prevIds);
        foreach ($newIds as $id) {
            if (! isset($prevIdsSet[$id])) {
                return;
            }
        }

        $removedIds = array_values(array_diff($prevIds, $newIds));

        Log::warning('moodboard_history: soft anti-rollback — sharp object count drop (strict subset)', [
            'moodboard_id' => $moodboardId,
            'prev_version' => (int) $latest->version,
            'next_version' => (int) $latest->version + 1,
            'prev_count' => $prevCount,
            'next_count' => $newCount,
            'removed_ids' => $removedIds,
        ]);
    }

    private function ensureMoodboardExists(string $moodboardId): void
    {
        MoodBoard::firstOrCreate(
            ['board_id' => $moodboardId],
            [
                'name' => 'Untitled Moodboard',
                'data' => [],
                'settings' => MoodBoard::getDefaultSettings(),
            ]
        );
    }

    private function buildStateHash(array $state): string
    {
        $encodedState = json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );

        if ($encodedState === false) {
            $encodedState = '{}';
        }

        return hash('sha256', $encodedState);
    }
}
