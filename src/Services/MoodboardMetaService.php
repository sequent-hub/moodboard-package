<?php

namespace Futurello\MoodBoard\Services;

use Futurello\MoodBoard\Models\MoodBoard;

class MoodboardMetaService
{
    public function saveMeta(
        string $moodboardId,
        array $settings,
        ?string $name = null,
        ?string $description = null
    ): array {
        $moodboard = MoodBoard::findByBoardId($moodboardId);

        if (!$moodboard) {
            $moodboard = MoodBoard::create([
                'board_id' => $moodboardId,
                'name' => $name ?? 'Untitled Moodboard',
                'description' => $description,
                'data' => [],
                'settings' => $settings,
            ]);

            return [
                'created' => true,
                'moodboardId' => $moodboard->board_id,
            ];
        }

        $payload = [
            'settings' => $settings,
        ];

        if ($name !== null) {
            $payload['name'] = $name;
        }

        if ($description !== null) {
            $payload['description'] = $description;
        }

        $moodboard->update($payload);

        return [
            'created' => false,
            'moodboardId' => $moodboard->board_id,
        ];
    }
}
