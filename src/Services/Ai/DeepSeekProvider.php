<?php

namespace Futurello\MoodBoard\Services\Ai;

use Futurello\MoodBoard\Services\Ai\Contracts\ChatProvider;
use Futurello\MoodBoard\Services\Ai\Exceptions\AiHttpException;
use Futurello\MoodBoard\Services\Ai\Support\SseStreamReader;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Клиент DeepSeek (OpenAI-совместимый Chat Completions API).
 *
 * Endpoint: https://api.deepseek.com/chat/completions
 * Auth:     Authorization: Bearer <api-key>
 *
 * Стриминг — стандартный SSE, дельта в choices[0].delta.content,
 * финальный маркер `data: [DONE]`.
 *
 * Порт server/src/providers/deepseek.js.
 */
class DeepSeekProvider implements ChatProvider
{
    private const ENDPOINT = 'https://api.deepseek.com/chat/completions';

    /**
     * @param  array{api_key: string, default_model: string}  $config
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
        return ! empty($this->config['api_key']);
    }

    public function chat(array $payload): array
    {
        $this->ensureEnabled();

        $response = $this->sendRequest($payload, stream: false);

        $json = $response->json();
        $text = $json['choices'][0]['message']['content'] ?? '';

        return ['text' => (string) $text];
    }

    public function chatStream(array $payload): iterable
    {
        $this->ensureEnabled();

        $response = $this->sendRequest($payload, stream: true);

        $body = $response->toPsrResponse()->getBody();

        foreach (SseStreamReader::events($body) as $event) {
            if ($event['data'] === '[DONE]') {
                return;
            }

            $json = json_decode($event['data'], true);
            if (! is_array($json)) {
                continue;
            }

            $delta = $json['choices'][0]['delta']['content'] ?? null;
            if (is_string($delta) && $delta !== '') {
                yield $delta;
            }
        }
    }

    private function ensureEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new AiHttpException(503, 'DeepSeek provider is not configured');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendRequest(array $payload, bool $stream): \Illuminate\Http\Client\Response
    {
        $body = [
            'model' => $payload['model'] ?? $this->config['default_model'],
            'messages' => $payload['messages'],
            'stream' => $stream,
            'temperature' => $payload['temperature'] ?? 0.7,
            'max_tokens' => $payload['maxTokens'] ?? 2000,
        ];

        try {
            $request = $this->http
                ->withHeaders([
                    'Authorization' => 'Bearer '.$this->config['api_key'],
                    'Accept' => $stream ? 'text/event-stream' : 'application/json',
                ])
                ->connectTimeout($this->httpConfig['connect_timeout'])
                ->timeout($this->httpConfig['timeout'])
                ->asJson();

            if ($stream) {
                $request = $request->withOptions(['stream' => true]);
            }

            $response = $request->post(self::ENDPOINT, $body);
        } catch (ConnectionException $e) {
            throw new AiHttpException(502, 'DeepSeek API unreachable: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            $details = $response->json() ?? $response->body();
            throw new AiHttpException(
                $response->status(),
                'DeepSeek API error ('.$response->status().')',
                $details,
            );
        }

        return $response;
    }
}
