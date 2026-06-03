<?php

namespace Futurello\MoodBoard\Services\Ai;

use Futurello\MoodBoard\Services\Ai\Contracts\ImageProvider;
use Futurello\MoodBoard\Services\Ai\Exceptions\AiHttpException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Клиент YandexART (Yandex Cloud Foundation Models).
 *
 * Генерация изображений асинхронная: первый запрос создаёт operation,
 * потом сервер опрашивает Operations API до готовности и возвращает base64.
 *
 * Порт server/src/providers/yandexArt.js.
 */
class YandexArtProvider implements ImageProvider
{
    private const GENERATE_ENDPOINT = 'https://llm.api.cloud.yandex.net/foundationModels/v1/imageGenerationAsync';

    private const OPERATIONS_ENDPOINT = 'https://llm.api.cloud.yandex.net:443/operations';

    /**
     * @param  array{
     *     api_key: string,
     *     folder_id: string,
     *     art_model_uri: string,
     *     poll_interval_ms: int,
     *     timeout_ms: int
     * }  $config
     * @param  array{connect_timeout: int, timeout: int}  $httpConfig
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
        private readonly array $httpConfig,
    ) {
    }

    public function isEnabled(): bool
    {
        return ! empty($this->config['api_key']) && ! empty($this->config['folder_id']);
    }

    public function generateImage(array $payload): array
    {
        if (! empty($payload['referenceImages']) && is_array($payload['referenceImages'])) {
            throw new AiHttpException(501, 'YandexART provider does not support reference images');
        }

        if (! $this->isEnabled()) {
            throw new AiHttpException(503, 'YandexART provider is not configured');
        }

        $operationId = $this->createOperation($payload);
        $operation = $this->waitForOperation($operationId);

        $image = $operation['response']['image'] ?? null;
        if (! is_string($image) || $image === '') {
            throw new AiHttpException(502, 'YandexART response does not contain image data', $operation);
        }

        return [
            'operationId' => $operationId,
            'imageBase64' => $image,
            'mimeType' => $payload['mimeType'] ?? 'image/jpeg',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createOperation(array $payload): string
    {
        try {
            $response = $this->http
                ->withHeaders($this->headers())
                ->connectTimeout($this->httpConfig['connect_timeout'])
                ->timeout($this->httpConfig['timeout'])
                ->asJson()
                ->post(self::GENERATE_ENDPOINT, $this->buildGenerationBody($payload));
        } catch (ConnectionException $e) {
            throw new AiHttpException(502, 'YandexART API unreachable: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            $details = $response->json() ?? $response->body();
            throw new AiHttpException(
                $response->status(),
                'YandexART API error ('.$response->status().')',
                $details,
            );
        }

        $json = $response->json();
        if (! is_array($json) || ! is_string($json['id'] ?? null) || $json['id'] === '') {
            throw new AiHttpException(502, 'YandexART did not return operation id', $json);
        }

        return $json['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForOperation(string $operationId): array
    {
        $startedAt = (int) (microtime(true) * 1000);
        $pollIntervalUs = $this->config['poll_interval_ms'] * 1000;
        $timeoutMs = $this->config['timeout_ms'];

        while (((int) (microtime(true) * 1000)) - $startedAt < $timeoutMs) {
            $operation = $this->fetchOperation($operationId);

            if (($operation['done'] ?? false) === true) {
                if (isset($operation['error'])) {
                    throw new AiHttpException(502, 'YandexART operation failed', $operation['error']);
                }

                return $operation;
            }

            usleep($pollIntervalUs);
        }

        throw new AiHttpException(504, 'YandexART operation timed out', ['operationId' => $operationId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOperation(string $operationId): array
    {
        try {
            $response = $this->http
                ->withHeaders($this->headers())
                ->connectTimeout($this->httpConfig['connect_timeout'])
                ->timeout($this->httpConfig['timeout'])
                ->get(self::OPERATIONS_ENDPOINT.'/'.rawurlencode($operationId));
        } catch (ConnectionException $e) {
            throw new AiHttpException(502, 'Yandex Operations API unreachable: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            $details = $response->json() ?? $response->body();
            throw new AiHttpException(
                $response->status(),
                'Yandex Operations API error ('.$response->status().')',
                $details,
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new AiHttpException(502, 'Yandex Operations API returned non-JSON response', $response->body());
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildGenerationBody(array $payload): array
    {
        $messages = [
            ['weight' => '1', 'text' => $payload['prompt']],
        ];

        if (! empty($payload['negativePrompt'])) {
            $messages[] = ['weight' => '-1', 'text' => $payload['negativePrompt']];
        }

        $generationOptions = [
            'aspectRatio' => [
                'widthRatio' => (string) $payload['widthRatio'],
                'heightRatio' => (string) $payload['heightRatio'],
            ],
        ];

        if (isset($payload['seed'])) {
            $generationOptions['seed'] = (string) $payload['seed'];
        }

        if (! empty($payload['mimeType'])) {
            $generationOptions['mimeType'] = $payload['mimeType'];
        }

        return [
            'modelUri' => $this->resolveModelUri($payload['model'] ?? null),
            'generationOptions' => $generationOptions,
            'messages' => $messages,
        ];
    }

    private function resolveModelUri(?string $model): string
    {
        if ($model !== null && str_starts_with($model, 'art://')) {
            return $model;
        }

        if ($model !== null && $model !== '' && ! empty($this->config['folder_id'])) {
            return 'art://'.$this->config['folder_id'].'/'.$model.'/latest';
        }

        if (! empty($this->config['art_model_uri'])) {
            return $this->config['art_model_uri'];
        }

        return 'art://'.$this->config['folder_id'].'/yandex-art/latest';
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Api-Key '.$this->config['api_key'],
            'x-folder-id' => $this->config['folder_id'],
        ];
    }
}
