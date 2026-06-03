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

    public function test_valid_reference_images_pass_validation_and_trigger_501(): void
    {
        // Валидация пропускает referenceImages → guard в провайдере кидает 501 (не 400, не 503).
        $this->postJson('/api/v2/ai/yandex-art/image', [
            'prompt' => 'sunset',
            'referenceImages' => [
                ['mimeType' => 'image/jpeg', 'data' => 'BASE64=='],
            ],
        ])
            ->assertStatus(501)
            ->assertJsonPath('error', 'YandexART provider does not support reference images');
    }

    public function test_validation_rejects_reference_image_without_mime_type(): void
    {
        $this->postJson('/api/v2/ai/yandex-art/image', [
            'prompt' => 'sunset',
            'referenceImages' => [
                ['data' => 'BASE64=='],
            ],
        ])
            ->assertStatus(400)
            ->assertJsonStructure(['error', 'details']);
    }

    public function test_empty_reference_images_array_does_not_trigger_guard(): void
    {
        Http::fake([
            'llm.api.cloud.yandex.net/foundationModels/v1/imageGenerationAsync' => Http::response(['id' => 'op-ref'], 200),
            'llm.api.cloud.yandex.net:443/operations/op-ref' => Http::response([
                'done' => true,
                'response' => ['image' => 'REFDATA'],
            ], 200),
        ]);

        // Пустой массив referenceImages → ключ не кладётся в payload → guard не срабатывает.
        $this->postJson('/api/v2/ai/yandex-art/image', [
            'prompt' => 'sunset',
            'referenceImages' => [],
        ])
            ->assertOk()
            ->assertJsonPath('imageBase64', 'REFDATA');
    }
}
