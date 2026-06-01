<?php

namespace Futurello\MoodBoard\Tests\Feature\Ai;

use Futurello\MoodBoard\Services\Ai\Support\ProviderRegistry;
use Futurello\MoodBoard\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * POST /api/v2/ai/{provider}/chat (non-stream).
 *
 * Контракт ответа повторяет server/src/routes/ai.js:
 *   stream=false → { text: "..." }
 *   ошибка       → { error: "...", details?: ... } со статусом провайдера/валидатора.
 */
class AiChatApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('moodboard-ai.providers.deepseek', [
            'api_key' => 'sk-fake-key',
            'default_model' => 'deepseek-chat',
        ]);
        $this->app['config']->set('moodboard-ai.providers.yandex', [
            'api_key' => 'AQVN-fake',
            'folder_id' => 'b1g-fake',
            'default_model_uri' => 'gpt://b1g-fake/yandexgpt/latest',
        ]);

        $this->app->forgetInstance(ProviderRegistry::class);
    }

    public function test_deepseek_non_stream_returns_assistant_text(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Привет от DeepSeek'],
                ]],
            ], 200),
        ]);

        $this->postJson('/api/v2/ai/deepseek/chat', [
            'messages' => [
                ['role' => 'user', 'content' => 'привет'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('text', 'Привет от DeepSeek');
    }

    public function test_deepseek_propagates_provider_error_with_unified_format(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response(['error' => 'invalid_api_key'], 401),
        ]);

        $this->postJson('/api/v2/ai/deepseek/chat', [
            'messages' => [
                ['role' => 'user', 'content' => 'привет'],
            ],
        ])
            ->assertStatus(401)
            ->assertJsonPath('error', 'DeepSeek API error (401)')
            ->assertJsonPath('details.error', 'invalid_api_key');
    }

    public function test_validation_error_uses_400_with_error_message(): void
    {
        $this->postJson('/api/v2/ai/deepseek/chat', [
            'messages' => [],
        ])
            ->assertStatus(400)
            ->assertJsonStructure(['error', 'details']);
    }

    public function test_unknown_provider_returns_404(): void
    {
        $this->postJson('/api/v2/ai/unknown/chat', [
            'messages' => [
                ['role' => 'user', 'content' => 'hi'],
            ],
        ])
            ->assertStatus(404)
            ->assertJsonPath('error', 'Unknown provider: unknown');
    }

    public function test_disabled_provider_returns_503(): void
    {
        $this->app['config']->set('moodboard-ai.providers.deepseek.api_key', '');
        $this->app->forgetInstance(ProviderRegistry::class);

        $this->postJson('/api/v2/ai/deepseek/chat', [
            'messages' => [
                ['role' => 'user', 'content' => 'hi'],
            ],
        ])
            ->assertStatus(503)
            ->assertJsonPath('error', 'Provider "deepseek" is not configured');
    }

    public function test_yandex_non_stream_uses_default_model_uri(): void
    {
        Http::fake([
            'llm.api.cloud.yandex.net/*' => Http::response([
                'result' => [
                    'alternatives' => [[
                        'message' => ['text' => 'Привет от Yandex'],
                    ]],
                ],
            ], 200),
        ]);

        $this->postJson('/api/v2/ai/yandex/chat', [
            'messages' => [
                ['role' => 'user', 'content' => 'hi'],
            ],
            'temperature' => 0.4,
            'maxTokens' => 100,
        ])
            ->assertOk()
            ->assertJsonPath('text', 'Привет от Yandex');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion'
                && ($body['modelUri'] ?? null) === 'gpt://b1g-fake/yandexgpt/latest'
                && ($body['completionOptions']['stream'] ?? null) === false
                && ($body['messages'][0]['text'] ?? null) === 'hi';
        });
    }
}
