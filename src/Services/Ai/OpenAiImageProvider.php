<?php

namespace Futurello\MoodBoard\Services\Ai;

use Futurello\MoodBoard\Services\Ai\Contracts\ImageProvider;
use Futurello\MoodBoard\Services\Ai\Exceptions\AiHttpException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

/**
 * Клиент OpenAI Images API.
 *
 * Текстовая генерация → POST /images/generations (JSON).
 * Генерация с опорными изображениями → POST /images/edits (multipart/form-data).
 *
 * Порт server/src/providers/openaiImage.js.
 */
class OpenAiImageProvider implements ImageProvider
{
    private const GENERATE_ENDPOINT = 'https://api.openai.com/v1/images/generations';

    private const EDIT_ENDPOINT = 'https://api.openai.com/v1/images/edits';

    private const DEFAULT_MODEL = 'gpt-image-1.5';

    /**
     * @param  array{api_key: string, image_model: string}  $config
     * @param  array{connect_timeout: int, timeout: int}  $httpConfig
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
        private readonly array $httpConfig,
    ) {}

    public function isEnabled(): bool
    {
        return ! empty($this->config['api_key']);
    }

    public function generateImage(array $payload): array
    {
        $hasReferences = ! empty($payload['referenceImages']) && is_array($payload['referenceImages']);

        $result = $hasReferences
            ? $this->createImageEdit($payload)
            : $this->createImageGeneration($payload);

        $image = $result['data'][0]['b64_json'] ?? null;
        if (! is_string($image) || $image === '') {
            throw new AiHttpException(502, 'OpenAI image response does not contain image data', $result);
        }

        return [
            'operationId' => isset($result['created']) ? (string) $result['created'] : null,
            'imageBase64' => $image,
            'mimeType' => $this->resolveMimeType($payload, $result),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createImageGeneration(array $payload): array
    {
        try {
            $response = $this->baseRequest()
                ->asJson()
                ->post(self::GENERATE_ENDPOINT, [
                    'model' => $this->resolveModel($payload),
                    'prompt' => $payload['prompt'],
                    'n' => 1,
                    'size' => $this->resolveSize($payload),
                    'output_format' => $this->resolveOutputFormat($payload),
                ]);
        } catch (ConnectionException $e) {
            throw new AiHttpException(502, 'OpenAI image API unreachable: '.$e->getMessage(), previous: $e);
        }

        return $this->parseResponse($response);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createImageEdit(array $payload): array
    {
        try {
            $request = $this->baseRequest()
                ->attach('model', $this->resolveModel($payload))
                ->attach('prompt', $payload['prompt'])
                ->attach('n', '1')
                ->attach('size', $this->resolveSize($payload))
                ->attach('output_format', $this->resolveOutputFormat($payload));

            foreach ($payload['referenceImages'] as $index => $image) {
                $bytes = base64_decode((string) $image['data'], true);
                if ($bytes === false) {
                    throw new AiHttpException(400, "referenceImages[{$index}].data is not valid base64");
                }
                $n = $index + 1;
                $ext = $this->extensionFromMime((string) ($image['mimeType'] ?? 'image/png'));
                $request = $request->attach('image[]', $bytes, "reference-{$n}.{$ext}");
            }

            $response = $request->post(self::EDIT_ENDPOINT);
        } catch (ConnectionException $e) {
            throw new AiHttpException(502, 'OpenAI image API unreachable: '.$e->getMessage(), previous: $e);
        }

        return $this->parseResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseResponse(\Illuminate\Http\Client\Response $response): array
    {
        if (! $response->successful()) {
            $details = $response->json() ?? $response->body();
            throw new AiHttpException(
                $response->status(),
                'OpenAI image API error ('.$response->status().')',
                $details,
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new AiHttpException(502, 'OpenAI image API returned non-JSON response', $response->body());
        }

        return $json;
    }

    private function baseRequest(): PendingRequest
    {
        return $this->http
            ->withToken($this->config['api_key'])
            ->connectTimeout($this->httpConfig['connect_timeout'])
            ->timeout($this->httpConfig['timeout']);
    }

    private function resolveModel(array $payload): string
    {
        $model = $payload['model'] ?? null;
        if (is_string($model) && $model !== '') {
            return $model;
        }

        return (string) ($this->config['image_model'] ?? self::DEFAULT_MODEL);
    }

    private function resolveSize(array $payload): string
    {
        $w = (int) ($payload['widthRatio'] ?? 1);
        $h = (int) ($payload['heightRatio'] ?? 1);

        if ($w === $h) {
            return '1024x1024';
        }

        return $w > $h ? '1536x1024' : '1024x1536';
    }

    private function resolveOutputFormat(array $payload): string
    {
        return match ($payload['mimeType'] ?? '') {
            'image/jpeg' => 'jpeg',
            'image/webp' => 'webp',
            default => 'png',
        };
    }

    private function resolveMimeType(array $payload, array $result): string
    {
        if (! empty($payload['mimeType'])) {
            return (string) $payload['mimeType'];
        }

        return match ($result['output_format'] ?? '') {
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    private function extensionFromMime(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
    }
}
