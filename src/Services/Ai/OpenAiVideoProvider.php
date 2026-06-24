<?php

namespace Futurello\MoodBoard\Services\Ai;

use Futurello\MoodBoard\Services\Ai\Contracts\VideoProvider;
use Futurello\MoodBoard\Services\Ai\Exceptions\AiHttpException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Клиент OpenAI Video (Sora). Порт server/src/providers/openaiVideo.js.
 *
 * Генерация асинхронная: POST /videos -> id, GET /videos/{id} -> status.
 * Контракт ответа 1:1 с Node-заглушкой.
 */
class OpenAiVideoProvider implements VideoProvider
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    private const DEFAULT_MODEL = 'sora-2';

    /** @var array<string, string> */
    private const SIZE_MAP = [
        '16:9' => '1280x720',
        '9:16' => '720x1280',
        '1:1' => '1080x1080',
        '4:3' => '1280x960',
        '3:4' => '960x1280',
    ];

    /**
     * @param  array{api_key?: string, base_url?: string, model?: string, timeout?: int}  $config
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

    public function submitVideo(array $payload): array
    {
        if (! $this->isEnabled()) {
            throw new AiHttpException(503, 'OpenAI video provider is not configured');
        }

        $ratio = (string) ($payload['ratio'] ?? '16:9');
        $size = self::SIZE_MAP[$ratio] ?? self::SIZE_MAP['16:9'];

        try {
            $response = $this->http
                ->asMultipart()
                ->withToken((string) $this->config['api_key'])
                ->connectTimeout($this->httpConfig['connect_timeout'])
                ->timeout($this->resolveTimeout())
                ->post($this->baseUrl().'/videos', [
                    'model' => (string) ($payload['model'] ?? self::DEFAULT_MODEL),
                    'prompt' => (string) $payload['prompt'],
                    'size' => $size,
                    'seconds' => (string) ($payload['duration'] ?? 4),
                ]);
        } catch (ConnectionException $e) {
            throw new AiHttpException(502, 'OpenAI video API unreachable: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new AiHttpException($response->status(), 'OpenAI video submit failed ('.$response->status().')', $response->json() ?? $response->body());
        }

        $jobId = $response->json('id');
        if (! is_string($jobId) || $jobId === '') {
            throw new AiHttpException(502, 'OpenAI video submit did not return id', $response->json());
        }

        return ['jobId' => $jobId];
    }

    public function pollVideo(string $jobId): array
    {
        if (! $this->isEnabled()) {
            throw new AiHttpException(503, 'OpenAI video provider is not configured');
        }

        try {
            $response = $this->http
                ->withToken((string) $this->config['api_key'])
                ->acceptJson()
                ->connectTimeout($this->httpConfig['connect_timeout'])
                ->timeout($this->resolveTimeout())
                ->get($this->baseUrl().'/videos/'.rawurlencode($jobId));
        } catch (ConnectionException $e) {
            throw new AiHttpException(502, 'OpenAI video API unreachable: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new AiHttpException($response->status(), 'OpenAI video poll failed ('.$response->status().')', $response->json() ?? $response->body());
        }

        $json = (array) $response->json();
        $status = (string) ($json['status'] ?? 'queued');

        if ($status === 'completed') {
            $videoUrl = $this->resolveVideoUrl($json);
            if ($videoUrl === null) {
                return $this->errorStatus('OpenAI video completed without output URL');
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
            $message = $json['error']['message'] ?? 'OpenAI video task failed';

            return $this->errorStatus((string) $message);
        }

        return [
            'status' => $status === 'in_progress' ? 'running' : 'pending',
            'progress' => $this->progressForStatus($status),
            'videoUrl' => null,
            'mimeType' => null,
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function resolveVideoUrl(array $json): ?string
    {
        $output = $json['output'] ?? null;

        if (is_string($output) && $output !== '') {
            return $output;
        }

        if (is_array($output)) {
            foreach ($output as $item) {
                if (is_array($item) && is_string($item['url'] ?? null) && $item['url'] !== '') {
                    return $item['url'];
                }
            }
        }

        if (is_string($json['url'] ?? null) && $json['url'] !== '') {
            return $json['url'];
        }

        return null;
    }

    /**
     * @return array{status: string, progress: int|null, videoUrl: null, mimeType: null, error: string}
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
            'queued' => 10,
            'in_progress' => 55,
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
