<?php

namespace Futurello\MoodBoard\Tests\Feature;

use Futurello\MoodBoard\Tests\TestCase;

class MoodboardLoadApiTest extends TestCase
{
    public function test_it_returns_latest_history_version_when_version_is_not_provided(): void
    {
        $moodboardId = 'mb-load-latest';

        $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => $moodboardId,
            'name' => 'Load Latest',
            'settings' => ['backgroundColor' => '#111111'],
        ])->assertOk();

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $moodboardId,
            'state' => ['objects' => [['id' => 'v1']]], 
        ])->assertOk()->assertJsonPath('historyVersion', 1);

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $moodboardId,
            'state' => ['objects' => [['id' => 'v2']]],
        ])->assertOk()->assertJsonPath('historyVersion', 2);

        $this->getJson("/api/v2/moodboard/{$moodboardId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.state.objects.0.id', 'v2');
    }

    public function test_it_returns_requested_history_version_when_version_is_provided(): void
    {
        $moodboardId = 'mb-load-specific';

        $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => $moodboardId,
            'name' => 'Load Specific',
            'settings' => ['backgroundColor' => '#222222'],
        ])->assertOk();

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $moodboardId,
            'state' => ['objects' => [['id' => 'old']]],
        ])->assertOk()->assertJsonPath('historyVersion', 1);

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $moodboardId,
            'state' => ['objects' => [['id' => 'new']]],
        ])->assertOk()->assertJsonPath('historyVersion', 2);

        $this->getJson("/api/v2/moodboard/{$moodboardId}/1")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.state.objects.0.id', 'old');
    }

    public function test_it_returns_404_when_moodboard_is_not_found(): void
    {
        $this->getJson('/api/v2/moodboard/missing-moodboard')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_it_returns_404_when_requested_version_is_not_found(): void
    {
        $moodboardId = 'mb-load-missing-version';

        $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => $moodboardId,
            'name' => 'Load Missing Version',
            'settings' => ['backgroundColor' => '#333333'],
        ])->assertOk();

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $moodboardId,
            'state' => ['objects' => [['id' => 'only-v1']]],
        ])->assertOk()->assertJsonPath('historyVersion', 1);

        $this->getJson("/api/v2/moodboard/{$moodboardId}/2")
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_it_returns_settings_from_moodboards_and_data_from_history(): void
    {
        $moodboardId = 'mb-load-composed';

        $this->postJson('/api/v2/moodboard/metadata/save', [
            'moodboardId' => $moodboardId,
            'name' => 'Load Composed',
            'description' => 'Metadata source',
            'settings' => ['backgroundColor' => '#abcdef', 'grid' => ['type' => 'line']],
        ])->assertOk();

        $this->postJson('/api/v2/moodboard/history/save', [
            'moodboardId' => $moodboardId,
            'state' => [
                'objects' => [['id' => 'obj-1', 'type' => 'note']],
                'camera' => ['zoom' => 1.5],
            ],
        ])->assertOk();

        $this->getJson("/api/v2/moodboard/{$moodboardId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Load Composed')
            ->assertJsonPath('data.description', 'Metadata source')
            ->assertJsonPath('data.settings.backgroundColor', '#abcdef')
            ->assertJsonPath('data.state.objects.0.id', 'obj-1')
            ->assertJsonPath('data.state.camera.zoom', 1.5);
    }
}
