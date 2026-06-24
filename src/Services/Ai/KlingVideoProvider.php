<?php

namespace Futurello\MoodBoard\Services\Ai;

use Futurello\MoodBoard\Services\Ai\Contracts\VideoProvider;
use Futurello\MoodBoard\Services\Ai\Exceptions\AiHttpException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Клиент Kling Video (Kuaishou). Порт server/src/providers/kling.js.
 *
 * Аутентификация — JWT HS256 (iss=accessKey), подпись secretKey.
 * Генерация асинхронная: POST /v1/videos/text2video -> task_id,
 * GET /v1/videos/text2video/{id} -> task_status. Контракт 1:1 с Node-заглушкой.
 */
class KlingVideoProvider implements VideoProvider
{
    private const DEFAULT_BASE_URL = 'https://api-singapore.klingai.com';

    /** @var array<string, string> */
    private const MODEL_MAP = [
        'kling-v3-pro' => 'kling-v3',
        'kling-v3' => 'kling-v3',
        'kling-v2.5-turbo-pro' => 'kling-v2-5-turbo',
        'kling-v2-5-turbo-pro' => 'kling-v2-5-turbo',
        'kling-v2-5-turbo' => 'kling-v2-5-turbo',
    ];

    /**
     * @param  array{access_key?: string, secret_key?: string, base_url?: string, timeout?: int}  $config
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
        return ! empty($this->config['access_key']) && ! empty($this->config['secret_key']);
    }

    public function submitVideo(array $payload): array
    {
        if (! $this->isEnabled()) {
            throw new AiHttpException(503, 'Kling provider is not configured');
        }

        try {
            $response = $this->http
                ->withHeaders($this->headers())
                ->connectTimeout($this->httpConfig['connect_timeout'])
                ->timeout($this->resolveTimeout())
                ->post($this->baseUrl().'/v1/videos/text2video', $this->submitBody($payload));
        } catch (ConnectionException $e) {
            throw new AiHttpException(502, 'Kling API unreachable: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new AiHttpException($response->status(), 'Kling submit failed ('.$response->status().')', $response->json() ?? $response->body());
        }

        $json = (array) $response->json();
        $code = $json['code'] ?? null;
        $taskId = $json['data']['task_id'] ?? null;

        if ($code !== 0 || ! is_string($taskId) || $taskId === '') {
            throw new AiHttpException(502, 'Kling submit did not return task id', $json);
        }

        return ['jobId' => $taskId];
    }

    public function pollVideo(string $jobId): array
    {
        if (! $this->isEnabled()) {
            throw new AiHttpException(503, 'Kling provider is not configured');
        }

        try {
            $response = $this->http
                ->withHeaders($this->headers())
                ->connectTimeout($this->httpConfig['connect_timeout'])
                ->timeout($this->resolveTimeout())
                ->get($this->baseUrl().'/v1/videos/text2video/'.rawurlencode($jobId));
        } catch (ConnectionException $e) {
            throw new AiHttpException(502, 'Kling API unreachable: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new AiHttpException($response->status(), 'Kling poll failed ('.$response->status().')', $response->json() ?? $response->body());
        }

        $json = (array) $response->json();
        $status = (string) ($json['data']['task_status'] ?? 'submitted');

        if ($status === 'succeed') {
            $videoUrl = $json['data']['task_result']['videos'][0]['url'] ?? null;
            if (! is_string($videoUrl) || $videoUrl === '') {
                return $this->errorStatus('Kling returned success without video URL');
            }

            return [
                'status' => 'done',
                'progress' => 100,
                'videoUrl' => $videoUrl,
                'mimeType' => 'video/mp4',
                'error' => null,
            ];
        }

        if ($status === 'failed') {
            return $this->errorStatus('Kling video task failed');
        }

        return [
            'status' => $status === 'processing' ? 'running' : 'pending',
            'progress' => $this->progressForStatus($status),
            'videoUrl' => null,
            'mimeType' => null,
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function submitBody(array $payload): array
    {
        $body = [
            'model_name' => $this->normalizeModel($payload['model'] ?? null),
            'prompt' => (string) $payload['prompt'],
            'mode' => 'pro',
            'duration' => (string) ($payload['duration'] ?? 5),
            'aspect_ratio' => (string) ($payload['ratio'] ?? '16:9'),
        ];

        if (isset($payload['seed'])) {
            $body['seed'] = (int) $payload['seed'];
        }

        if (! empty($payload['negativePrompt'])) {
            $body['negative_prompt'] = (string) $payload['negativePrompt'];
        }

        if (isset($payload['cfgScale'])) {
            $body['cfg_scale'] = (float) $payload['cfgScale'];
        }

        return $body;
    }

    private function normalizeModel(?string $model): string
    {
        return self::MODEL_MAP[$model] ?? self::MODEL_MAP['kling-v3'];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->createJwt(),
        ];
    }

    /**
     * JWT HS256: header.payload.signature, base64url без паддинга.
     */
    private function createJwt(): string
    {
        $now = time();
        $header = $this->base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $payload = $this->base64Url(json_encode([
            'iss' => (string) $this->config['access_key'],
            'exp' => $now + 1800,
            'nbf' => $now - 5,
        ], JSON_UNESCAPED_SLASHES));
        $signing = $header.'.'.$payload;
        $signature = hash_hmac('sha256', $signing, (string) $this->config['secret_key'], true);

        return $signing.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @return array{status: string, progress: null, videoUrl: null, mimeType: null, error: string}
     */
    private function errorStatus(string $message): array
    {
        return [
            'status' => 'error',
            'progress' => null,
            'videoUrl' => null,
            'mimeType' => null,
            'error' => $message,
        ];
    }

    private function progressForStatus(string $status): int
    {
        return match ($status) {
            'submitted' => 10,
            'processing' => 50,
            default => 20,
        };
    }

    private function baseUrl(): string
    {
        $base = (string) ($this->config['base_url'] ?? self::DEFAULT_BASE_URL);

        return rtrim($base !== '' ? $base : self::DEFAULT_BASE_URL, '/');
    }

    private function resolveTimeout(): int
    {
        $timeout = (int) ($this->config['timeout'] ?? 0);

        return $timeout > 0 ? $timeout : (int) $this->httpConfig['timeout'];
    }
}
