<?php

namespace Futurello\MoodBoard\Services\Ai\Support;

use Futurello\MoodBoard\Services\Ai\Contracts\ChatProvider;
use Futurello\MoodBoard\Services\Ai\Contracts\ImageProvider;
use Futurello\MoodBoard\Services\Ai\Contracts\Model3dProvider;
use Futurello\MoodBoard\Services\Ai\Exceptions\AiHttpException;

/**
 * Реестр AI-провайдеров (порт реестра из server/src/routes/ai.js).
 *
 * Контейнер держит здесь готовые инстансы, контроллер достаёт их
 * по строковому id из URL (param :provider).
 *
 * Маппинг id -> human label используется в GET /api/v2/ai/providers.
 */
class ProviderRegistry
{
    /**
     * @param  array<string, array{label: string, provider?: ChatProvider|ImageProvider, supportedRatios: list<string>|null}>  $entries
     *
     * provider — опционален: запись без провайдера попадает в /providers с enabled=false
     * и служит единственным источником метаданных (supportedRatios) для фронта.
     * supportedRatios — список id форматов из FORMAT_OPTIONS на фронте, которые поддерживает провайдер
     * (например ['1:1','3:2','2:3']), либо null — без ограничений (фронт показывает все форматы).
     * Зеркальный контракт описан в server/src/routes/ai.js (Node-заглушка для dev).
     */
    public function __construct(private readonly array $entries)
    {
    }

    public function chat(string $id): ChatProvider
    {
        $entry = $this->entries[$id] ?? null;
        if ($entry === null) {
            throw new AiHttpException(404, "Unknown provider: {$id}");
        }

        $provider = $entry['provider'] ?? null;
        if (! $provider instanceof ChatProvider) {
            throw new AiHttpException(404, "Provider \"{$id}\" does not support chat");
        }

        if (! $provider->isEnabled()) {
            throw new AiHttpException(503, "Provider \"{$id}\" is not configured");
        }

        return $provider;
    }

    public function image(string $id): ImageProvider
    {
        $entry = $this->entries[$id] ?? null;
        if ($entry === null) {
            throw new AiHttpException(404, "Unknown provider: {$id}");
        }

        $provider = $entry['provider'] ?? null;
        if (! $provider instanceof ImageProvider) {
            throw new AiHttpException(404, "Provider \"{$id}\" does not support image generation");
        }

        if (! $provider->isEnabled()) {
            throw new AiHttpException(503, "Provider \"{$id}\" is not configured");
        }

        return $provider;
    }

    public function model3d(string $id): Model3dProvider
    {
        $entry = $this->entries[$id] ?? null;
        if ($entry === null) {
            throw new AiHttpException(404, "Unknown provider: {$id}");
        }

        $provider = $entry['provider'];
        if (! $provider instanceof Model3dProvider) {
            throw new AiHttpException(404, "Provider \"{$id}\" does not support 3D model generation");
        }

        if (! $provider->isEnabled()) {
            throw new AiHttpException(503, "Provider \"{$id}\" is not configured");
        }

        return $provider;
    }

    /**
     * @return list<array{id: string, label: string, enabled: bool, supportedRatios: list<string>|null}>
     */
    public function list(): array
    {
        $list = [];
        foreach ($this->entries as $id => $entry) {
            $provider = $entry['provider'] ?? null;
            $list[] = [
                'id'              => $id,
                'label'           => $entry['label'],
                'enabled'         => $provider !== null && $provider->isEnabled(),
                'supportedRatios' => $entry['supportedRatios'] ?? null,
            ];
        }

        return $list;
    }
}
