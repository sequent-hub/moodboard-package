<?php

namespace Futurello\MoodBoard\Services\Ai\Contracts;

/**
 * Абстракция провайдера генерации видео (OpenAI Sora, Kling).
 *
 * Генерация асинхронная: submitVideo создаёт джоб и возвращает jobId,
 * клиент опрашивает pollVideo до статуса done/error.
 *
 * submitVideo payload (нормализованный из AiVideoRequest, 1:1 с
 * server/src/utils/schema.js:parseVideoPayload):
 * [
 *   'prompt'           => string,
 *   'negativePrompt'   => string|null,
 *   'model'            => string|null,
 *   'ratio'            => string|null,
 *   'resolution'       => string|null,
 *   'duration'         => int|null,
 *   'seed'             => int|null,
 *   'audio'            => bool|null,
 *   'watermark'        => bool|null,
 *   'cfgScale'         => float|null,
 *   'personGeneration' => string|null,
 *   'referenceImages'  => list<array{mimeType: string, data: string}>|null,
 * ]
 *
 * pollVideo возвращает нормализованный статус. videoUrl — ВРЕМЕННЫЙ URL
 * провайдера; persist (скачивание в хранилище доски) делает контроллер,
 * как для model3d:
 * [
 *   'status'   => 'pending'|'running'|'done'|'error',
 *   'progress' => int|null,
 *   'videoUrl' => string|null,
 *   'mimeType' => string|null,
 *   'error'    => string|null,
 * ]
 */
interface VideoProvider
{
    public function isEnabled(): bool;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{jobId: string}
     */
    public function submitVideo(array $payload): array;

    /**
     * @return array{status: string, progress: int|null, videoUrl: string|null, mimeType: string|null, error: string|null}
     */
    public function pollVideo(string $jobId): array;
}
