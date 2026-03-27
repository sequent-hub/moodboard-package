<?php

namespace Futurello\MoodBoard\Repositories;

use Carbon\CarbonInterface;
use Futurello\MoodBoard\Models\MoodboardHistory;

class MoodboardHistoryRepository
{
    public function findLatestByMoodboardId(string $moodboardId): ?MoodboardHistory
    {
        return MoodboardHistory::query()
            ->where('moodboard_id', $moodboardId)
            ->orderByDesc('version')
            ->first();
    }

    public function append(
        string $moodboardId,
        int $version,
        array $stateJson,
        string $stateHash,
        string $actionType,
        CarbonInterface $createdAt,
        ?string $createdBy
    ): MoodboardHistory {
        return MoodboardHistory::query()->create([
            'moodboard_id' => $moodboardId,
            'version' => $version,
            'state_json' => $stateJson,
            'state_hash' => $stateHash,
            'action_type' => $actionType,
            'created_at' => $createdAt,
            'created_by' => $createdBy,
        ]);
    }
}
