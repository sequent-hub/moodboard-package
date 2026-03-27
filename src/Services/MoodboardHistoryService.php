<?php

namespace Futurello\MoodBoard\Services;

use Futurello\MoodBoard\Repositories\MoodboardHistoryRepository;

class MoodboardHistoryService
{
    public function __construct(
        private readonly MoodboardHistoryRepository $historyRepository
    ) {
    }

    public function saveSnapshot(
        string $moodboardId,
        array $state,
        string $actionType = 'command_execute',
        ?string $createdBy = null
    ): array {
        $stateHash = $this->buildStateHash($state);
        $latest = $this->historyRepository->findLatestByMoodboardId($moodboardId);

        if ($latest && hash_equals($latest->state_hash, $stateHash)) {
            return [
                'deduplicated' => true,
                'historyVersion' => (int) $latest->version,
            ];
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
