<?php

namespace Futurello\MoodBoard\Services\Ai\Contracts;

/**
 * Абстракция провайдера генерации 3D-моделей (сейчас — Tencent Hunyuan 3D).
 *
 * Генерация асинхронная: submitModel3d создаёт джоб и возвращает jobId,
 * клиент опрашивает queryModel3d до статуса done/error.
 *
 * submitModel3d payload (нормализованный из AiModel3dRequest):
 * [
 *   'imageBase64' => string,   // base64 без data:-префикса
 *   'imageMime'   => string,   // напр. image/png
 *   'faceCount'   => int|null,
 *   'generateType'=> string,   // Normal | Geometry
 *   'enablePbr'   => bool,
 * ]
 *
 * queryModel3d возвращает нормализованный статус (без app-уровневого
 * хранения файлов — этим занимается контроллер):
 * [
 *   'status'     => 'pending'|'running'|'done'|'error',
 *   'glbUrl'     => string|null,  // временный URL Tencent на .glb
 *   'previewUrl' => string|null,  // URL превью-картинки, если есть
 *   'error'      => string|null,
 * ]
 */
interface Model3dProvider
{
    public function isEnabled(): bool;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{jobId: string}
     */
    public function submitModel3d(array $payload): array;

    /**
     * @return array{status: string, glbUrl: string|null, previewUrl: string|null, error: string|null}
     */
    public function queryModel3d(string $jobId): array;
}
