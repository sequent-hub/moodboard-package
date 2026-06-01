<?php

namespace Futurello\MoodBoard\Services\Ai\Support;

use Futurello\MoodBoard\Services\Ai\Contracts\ChatProvider;
use Futurello\MoodBoard\Services\Ai\Contracts\ImageProvider;
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
     * @param  array<string, array{label: string, provider: ChatProvider|ImageProvider}>  $entries
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

        $provider = $entry['provider'];
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

        $provider = $entry['provider'];
        if (! $provider instanceof ImageProvider) {
            throw new AiHttpException(404, "Provider \"{$id}\" does not support image generation");
        }

        if (! $provider->isEnabled()) {
            throw new AiHttpException(503, "Provider \"{$id}\" is not configured");
        }

        return $provider;
    }

    /**
     * @return list<array{id: string, label: string, enabled: bool}>
     */
    public function list(): array
    {
        $list = [];
        foreach ($this->entries as $id => $entry) {
            $list[] = [
                'id' => $id,
                'label' => $entry['label'],
                'enabled' => $entry['provider']->isEnabled(),
            ];
        }

        return $list;
    }
}
