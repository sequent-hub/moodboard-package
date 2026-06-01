<?php

namespace Futurello\MoodBoard\Services\Ai;

use Futurello\MoodBoard\Services\Ai\Contracts\ChatProvider;
use Futurello\MoodBoard\Services\Ai\Exceptions\AiHttpException;
use Futurello\MoodBoard\Services\Ai\Support\SseStreamReader;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Клиент YandexGPT (Yandex Cloud Foundation Models).
 *
 * Endpoint: https://llm.api.cloud.yandex.net/foundationModels/v1/completion
 * Auth:     Authorization: Api-Key <static service-account key>
 *
 * В стриминг-режиме Yandex отдаёт JSONL: по объекту на строку, без "data:".
 * Каждый чанк содержит НАКОПЛЕННЫЙ текст в alternatives[0].message.text —
 * поэтому считаем дельту между prev и next.
 *
 * Порт server/src/providers/yandex.js.
 */
class YandexProvider implements ChatProvider
{
    private const ENDPOINT = 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion';

    /**
     * @param  array{api_key: string, folder_id: string, default_model_uri: string}  $config
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

    public function chat(array $payload): array
    {
        $this->ensureEnabled();

        $response = $this->sendRequest($payload, stream: false);

        $json = $response->json();
        $text = $json['result']['alternatives'][0]['message']['text'] ?? '';

        return ['text' => (string) $text];
    }

    public function chatStream(array $payload): iterable
    {
        $this->ensureEnabled();

        $response = $this->sendRequest($payload, stream: true);

        $body = $response->toPsrResponse()->getBody();
        $prev = '';

        foreach (SseStreamReader::events($body) as $event) {
            $json = json_decode($event['data'], true);
            if (! is_array($json)) {
                continue;
            }

            $next = $json['result']['alternatives'][0]['message']['text'] ?? '';
            $nextLen = strlen($next);
            $prevLen = strlen($prev);

            if ($nextLen > $prevLen) {
                yield substr($next, $prevLen);
                $prev = $next;
            }
        }
    }

    private function ensureEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new AiHttpException(503, 'Yandex provider is not configured');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendRequest(array $payload, bool $stream): \Illuminate\Http\Client\Response
    {
        $modelUri = $this->resolveModelUri($payload['model'] ?? null);
        $temperature = max(0.0, min(1.0, (float) ($payload['temperature'] ?? 0.6)));

        $body = [
            'modelUri' => $modelUri,
            'completionOptions' => [
                'stream' => $stream,
                'temperature' => $temperature,
                'maxTokens' => (string) ($payload['maxTokens'] ?? 2000),
            ],
            'messages' => array_map(
                static fn (array $m): array => ['role' => $m['role'], 'text' => $m['content']],
                $payload['messages'],
            ),
        ];

        try {
            $request = $this->http
                ->withHeaders([
                    'Authorization' => 'Api-Key '.$this->config['api_key'],
                    'x-folder-id' => $this->config['folder_id'],
                ])
                ->connectTimeout($this->httpConfig['connect_timeout'])
                ->timeout($this->httpConfig['timeout'])
                ->asJson();

            if ($stream) {
                $request = $request->withOptions(['stream' => true]);
            }

            $response = $request->post(self::ENDPOINT, $body);
        } catch (ConnectionException $e) {
            throw new AiHttpException(502, 'Yandex API unreachable: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            $details = $response->json() ?? $response->body();
            throw new AiHttpException(
                $response->status(),
                'Yandex API error ('.$response->status().')',
                $details,
            );
        }

        return $response;
    }

    private function resolveModelUri(?string $model): string
    {
        if ($model !== null && str_starts_with($model, 'gpt://')) {
            return $model;
        }

        if ($model !== null && $model !== '' && ! empty($this->config['folder_id'])) {
            return 'gpt://'.$this->config['folder_id'].'/'.$model.'/latest';
        }

        if (! empty($this->config['default_model_uri'])) {
            return $this->config['default_model_uri'];
        }

        throw new AiHttpException(400, 'Yandex modelUri is not specified (no model in payload, no default)');
    }
}
