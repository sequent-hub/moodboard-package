<?php

namespace Futurello\MoodBoard\Tests\Feature;

use Futurello\MoodBoard\Models\MoodBoard;
use Futurello\MoodBoard\Tests\TestCase;

class MoodboardMetaSaveApiTest extends TestCase
{
    public function test_it_creates_new_moodboard_with_new_metadata_and_settings(): void
    {
        $response = $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => 'mb-meta-new-001',
            'name' => 'New Meta Board',
            'description' => 'Created from v2 metadata endpoint',
            'settings' => [
                'backgroundColor' => '#ffffff',
                'grid' => ['type' => 'line'],
            ],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('moodboardId', 'mb-meta-new-001');

        $this->assertDatabaseHas('moodboards', [
            'board_id' => 'mb-meta-new-001',
            'name' => 'New Meta Board',
            'description' => 'Created from v2 metadata endpoint',
        ]);

        $moodboard = MoodBoard::findByBoardId('mb-meta-new-001');
        $this->assertNotNull($moodboard);
        $this->assertSame('#ffffff', $moodboard->settings['backgroundColor'] ?? null);
        $this->assertSame('line', $moodboard->settings['grid']['type'] ?? null);
    }

    public function test_it_rejects_invalid_metadata_payloads(): void
    {
        $this->postJson('/api/v2/moodboard/metadata/save', [
            'name' => 'Missing moodboardId',
            'settings' => ['backgroundColor' => '#ffffff'],
        ])->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => 'mb-meta-invalid-settings',
            'settings' => 'not-an-array',
        ])->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
