<?php

namespace Futurello\MoodBoard\Services\Ai\Contracts;

/**
 * Абстракция провайдера генерации изображений (сейчас — только YandexART).
 *
 * payload — нормализованный массив из AiImageRequest:
 * [
 *   'prompt'         => string,
 *   'negativePrompt' => string|null,
 *   'widthRatio'     => int,
 *   'heightRatio'    => int,
 *   'seed'           => int|null,
 *   'mimeType'       => string|null,
 *   'model'          => string|null,
 * ]
 *
 * Возвращает массив, который отдаётся клиенту as-is:
 * [ 'operationId' => '...', 'imageBase64' => '...', 'mimeType' => '...' ]
 */
interface ImageProvider
{
    public function isEnabled(): bool;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{operationId: string, imageBase64: string, mimeType: string}
     */
    public function generateImage(array $payload): array;
}
