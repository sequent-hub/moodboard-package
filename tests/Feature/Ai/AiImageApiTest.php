<?php

namespace Futurello\MoodBoard\Tests\Feature\Ai;

use Futurello\MoodBoard\Services\Ai\Support\ProviderRegistry;
use Futurello\MoodBoard\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * POST /api/v2/ai/yandex-art/image.
 *
 * Сценарий: первый запрос создаёт async operation, второй (polling) уже
 * возвращает done=true с image. Контракт ответа клиенту:
 *   { operationId, imageBase64, mimeType }
 */
class AiImageApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('moodboard-ai.providers.yandex_art', [
            'api_key' => 'AQVN-fake',
            'folder_id' => 'b1g-fake',
            'art_model_uri' => 'art://b1g-fake/yandex-art/latest',
            'poll_interval_ms' => 1,
            'timeout_ms' => 5000,
        ]);

        $this->app->forgetInstance(ProviderRegistry::class);
    }

    public function test_returns_base64_image_after_async_operation_completes(): void
    {
        Http::fake([
            'llm.api.cloud.yandex.net/foundationModels/v1/imageGenerationAsync' => Http::response([
                'id' => 'op-123',
            ], 200),
            'llm.api.cloud.yandex.net:443/operations/op-123' => Http::response([
                'done' => true,
                'response' => ['image' => 'BASE64DATA'],
            ], 200),
        ]);

        $this->postJson('/api/v2/ai/yandex-art/image', [
            'prompt' => 'sunset over mountains',
            'widthRatio' => 1,
            'heightRatio' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('operationId', 'op-123')
            ->assertJsonPath('imageBase64', 'BASE64DATA')
            ->assertJsonPath('mimeType', 'image/jpeg');
    }

    public function test_returns_unified_error_when_provider_returns_failed_operation(): void
    {
        Http::fake([
            'llm.api.cloud.yandex.net/foundationModels/v1/imageGenerationAsync' => Http::response([
                'id' => 'op-err',
            ], 200),
            'llm.api.cloud.yandex.net:443/operations/op-err' => Http::response([
                'done' => true,
                'error' => ['code' => 13, 'message' => 'internal'],
            ], 200),
        ]);

        $this->postJson('/api/v2/ai/yandex-art/image', [
            'prompt' => 'fail please',
        ])
            ->assertStatus(502)
            ->assertJsonPath('error', 'YandexART operation failed');
    }

    public function test_validation_rejects_empty_prompt(): void
    {
        $this->postJson('/api/v2/ai/yandex-art/image', [
            'prompt' => '',
        ])
            ->assertStatus(400)
            ->assertJsonStructure(['error', 'details']);
    }
}
