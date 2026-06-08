<?php

namespace Futurello\MoodBoard\Tests\Feature\Ai;

use Futurello\MoodBoard\Services\Ai\Support\ProviderRegistry;
use Futurello\MoodBoard\Tests\TestCase;
use Futurello\MoodBoard\Services\Ai\Contracts\ChatProvider;

/**
 * GET /api/v2/ai/providers — список провайдеров с флагом enabled
 * и полем supportedRatios, вычисляемым по конфигурации реестра.
 */
class AiProvidersApiTest extends TestCase
{
    public function test_lists_providers_with_enabled_flags_based_on_config(): void
    {
        $this->app['config']->set('moodboard-ai.providers.yandex', [
            'api_key' => 'AQVN-test',
            'folder_id' => 'b1g-test',
            'default_model_uri' => 'gpt://b1g-test/yandexgpt/latest',
        ]);
        $this->app['config']->set('moodboard-ai.providers.yandex_art', [
            'api_key' => 'AQVN-test',
            'folder_id' => 'b1g-test',
            'art_model_uri' => 'art://b1g-test/yandex-art/latest',
            'poll_interval_ms' => 100,
            'timeout_ms' => 5000,
        ]);
        $this->app['config']->set('moodboard-ai.providers.deepseek', [
            'api_key' => '',
            'default_model' => 'deepseek-chat',
        ]);

        $this->app->forgetInstance(ProviderRegistry::class);

        $this->getJson('/api/v2/ai/providers')
            ->assertOk()
            ->assertJsonPath('providers.0.id', 'yandex')
            ->assertJsonPath('providers.0.enabled', true)
            ->assertJsonPath('providers.0.supportedRatios', null)
            ->assertJsonPath('providers.1.id', 'yandex-art')
            ->assertJsonPath('providers.1.enabled', true)
            ->assertJsonPath('providers.1.supportedRatios', null)
            ->assertJsonPath('providers.2.id', 'deepseek')
            ->assertJsonPath('providers.2.enabled', false)
            ->assertJsonPath('providers.2.supportedRatios', null);
    }

    public function test_supported_ratios_are_forwarded_as_is_in_provider_list(): void
    {
        // Проверяет контракт для будущего openai-image провайдера:
        // supportedRatios из реестра пробрасывается в JSON-ответ без изменений.
        $fakeProvider = new class implements ChatProvider {
            public function isEnabled(): bool { return true; }
            public function chat(array $payload): array { return ['text' => '']; }
            public function chatStream(array $payload): iterable { return []; }
        };

        $registry = new ProviderRegistry([
            'openai-image' => [
                'label'           => 'OpenAI Images',
                'provider'        => $fakeProvider,
                'supportedRatios' => ['1:1', '3:2', '2:3'],
            ],
        ]);

        $this->app->instance(ProviderRegistry::class, $registry);

        $this->getJson('/api/v2/ai/providers')
            ->assertOk()
            ->assertJsonPath('providers.0.id', 'openai-image')
            ->assertJsonPath('providers.0.supportedRatios', ['1:1', '3:2', '2:3']);
    }
}
