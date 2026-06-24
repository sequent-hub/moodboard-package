<?php

namespace Futurello\MoodBoard\Http\Controllers;

use Futurello\MoodBoard\Http\Requests\AiChatRequest;
use Futurello\MoodBoard\Http\Requests\AiImageRequest;
use Futurello\MoodBoard\Http\Requests\AiModel3dRequest;
use Futurello\MoodBoard\Http\Requests\AiVideoRequest;
use Futurello\MoodBoard\Services\Ai\Exceptions\AiHttpException;
use Futurello\MoodBoard\Services\Ai\Support\ProviderRegistry;
use Futurello\MoodBoard\Services\Ai\Support\SseStreamWriter;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * AI-роуты, портированные из server/src/routes/ai.js.
 *
 * Контракт ответов и формат ошибок выбраны умышленно совместимыми
 * с Node-заглушкой — фронт-пакет moodboard не меняет код парсинга
 * (только URL: /api/ai/* → /api/v2/ai/*).
 *
 *   GET  /api/v2/ai/providers
 *   POST /api/v2/ai/{provider}/chat              (stream=true ⇒ SSE)
 *   POST /api/v2/ai/yandex-art/image
 */
class AiController extends Controller
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly HttpFactory $http,
    ) {
    }

    public function providers(): JsonResponse
    {
        return response()->json(['providers' => $this->registry->list()]);
    }

    public function chat(string $provider, AiChatRequest $request): JsonResponse|StreamedResponse
    {
        try {
            $client = $this->registry->chat($provider);
            $payload = $request->normalized();
        } catch (AiHttpException $e) {
            return $this->errorResponse($e);
        }

        if ($payload['stream']) {
            return $this->streamChat($client, $payload);
        }

        try {
            $result = $client->chat($payload);
        } catch (AiHttpException $e) {
            return $this->errorResponse($e);
        } catch (Throwable $e) {
            return $this->errorResponse(new AiHttpException(500, 'Internal server error'));
        }

        return response()->json(['text' => $result['text']]);
    }

    public function image(string $provider, AiImageRequest $request): JsonResponse
    {
        try {
            $client = $this->registry->image($provider);
            $payload = $request->normalized();
            $result = $client->generateImage($payload);
        } catch (AiHttpException $e) {
            return $this->errorResponse($e);
        } catch (Throwable $e) {
            return $this->errorResponse(new AiHttpException(500, 'Internal server error'));
        }

        return response()->json($result);
    }

    /**
     * POST /api/v2/ai/{provider}/video — создать джоб генерации видео.
     */
    public function submitVideo(string $provider, AiVideoRequest $request): JsonResponse
    {
        try {
            $client = $this->registry->video($provider);
            $result = $client->submitVideo($request->normalized());
        } catch (AiHttpException $e) {
            return $this->errorResponse($e);
        } catch (Throwable $e) {
            Log::error('[moodboard.ai] video submit error', ['message' => $e->getMessage()]);

            return $this->errorResponse(new AiHttpException(500, 'Internal server error'));
        }

        return response()->json(['jobId' => $result['jobId']]);
    }

    /**
     * GET /api/v2/ai/{provider}/video/{jobId} — статус джоба.
     *
     * При DONE скачиваем видео с временного URL провайдера, кладём в хранилище
     * доски (как 3D-модели и картинки) и возвращаем уже наш durable-URL —
     * чтобы доска грузила стабильную ссылку, а не протухающую ссылку провайдера.
     */
    public function pollVideo(string $provider, string $jobId): JsonResponse
    {
        try {
            $client = $this->registry->video($provider);
            $status = $client->pollVideo($jobId);
        } catch (AiHttpException $e) {
            return $this->errorResponse($e);
        } catch (Throwable $e) {
            Log::error('[moodboard.ai] video poll error', ['message' => $e->getMessage()]);

            return $this->errorResponse(new AiHttpException(500, 'Internal server error'));
        }

        if (($status['status'] ?? null) !== 'done') {
            return response()->json([
                'status' => $status['status'] ?? 'pending',
                'progress' => $status['progress'] ?? ($status['status'] === 'running' ? 50 : 5),
                'videoUrl' => null,
                'mimeType' => null,
                'error' => $status['error'] ?? null,
            ]);
        }

        try {
            $videoUrl = $this->storeRemoteFile($status['videoUrl'], 'mp4', 'video/mp4', 'videos');
        } catch (Throwable $e) {
            Log::error('[moodboard.ai] video store error', ['message' => $e->getMessage()]);

            return $this->errorResponse(new AiHttpException(502, 'Не удалось сохранить видео'));
        }

        return response()->json([
            'status' => 'done',
            'progress' => 100,
            'videoUrl' => $videoUrl,
            'mimeType' => $status['mimeType'] ?? 'video/mp4',
            'error' => null,
        ]);
    }

    /**
     * POST /api/v2/ai/{provider}/model3d — создать джоб генерации 3D-модели.
     */
    public function submitModel3d(string $provider, AiModel3dRequest $request): JsonResponse
    {
        try {
            $client = $this->registry->model3d($provider);
            $result = $client->submitModel3d($request->normalized());
        } catch (AiHttpException $e) {
            return $this->errorResponse($e);
        } catch (Throwable $e) {
            Log::error('[moodboard.ai] model3d submit error', ['message' => $e->getMessage()]);

            return $this->errorResponse(new AiHttpException(500, 'Internal server error'));
        }

        return response()->json(['jobId' => $result['jobId']]);
    }

    /**
     * GET /api/v2/ai/{provider}/model3d/{jobId} — статус джоба.
     *
     * При DONE скачиваем GLB c временного URL Tencent, кладём в хранилище
     * доски (как картинки) и возвращаем уже наш URL — чтобы вьювер грузил
     * стабильную ссылку, а не протухающую за 24ч ссылку Tencent.
     */
    public function pollModel3d(string $provider, string $jobId): JsonResponse
    {
        try {
            $client = $this->registry->model3d($provider);
            $status = $client->queryModel3d($jobId);
        } catch (AiHttpException $e) {
            return $this->errorResponse($e);
        } catch (Throwable $e) {
            Log::error('[moodboard.ai] model3d poll error', ['message' => $e->getMessage()]);

            return $this->errorResponse(new AiHttpException(500, 'Internal server error'));
        }

        if ($status['status'] !== 'done') {
            return response()->json([
                'status' => $status['status'],
                'progress' => $status['status'] === 'running' ? 50 : 5,
                'stage' => null,
                'previewBase64' => null,
                'mimeType' => null,
                'modelUrl' => null,
                'format' => null,
                'error' => $status['error'] ?? null,
            ]);
        }

        try {
            $modelUrl = $this->storeRemoteFile($status['glbUrl'], 'glb', 'model/gltf-binary');
        } catch (Throwable $e) {
            Log::error('[moodboard.ai] model3d GLB store error', ['message' => $e->getMessage()]);

            return $this->errorResponse(new AiHttpException(502, 'Не удалось сохранить 3D-модель'));
        }

        [$previewBase64, $previewMime] = $this->fetchPreview($status['previewUrl'] ?? null);

        return response()->json([
            'status' => 'done',
            'progress' => 100,
            'stage' => null,
            'previewBase64' => $previewBase64,
            'mimeType' => $previewMime,
            'modelUrl' => $modelUrl,
            'format' => 'glb',
            'error' => null,
        ]);
    }

    /**
     * Скачивает файл по URL и кладёт в хранилище (S3/CDN или public disk),
     * возвращает публичный URL. Расширение задаём явно — вьювер матчит
     * загрузчик по расширению (.glb), не по content-sniff.
     */
    private function storeRemoteFile(string $sourceUrl, string $extension, string $mime, string $directory = 'models'): string
    {
        $response = $this->http->withOptions(['verify' => $this->hunyuanVerify()])->timeout(120)->get($sourceUrl);
        if (! $response->successful()) {
            throw new \RuntimeException('download failed: '.$response->status());
        }

        $bytes = $response->body();
        $filename = time().'_'.Str::random(10).'.'.$extension;
        $path = trim($directory, '/').'/'.date('Y/m').'/'.$filename;

        $cdnBaseUrl = trim((string) env('MOODBOARD_IMAGE_CDN_BASE_URL', ''));

        if ($cdnBaseUrl !== '') {
            Storage::disk('s3')->put($path, $bytes, ['ContentType' => $mime]);

            return rtrim($cdnBaseUrl, '/').'/'.ltrim($path, '/');
        }

        Storage::disk('public')->put($path, $bytes);

        return url('storage/'.$path);
    }

    /**
     * Тянет превью-картинку и возвращает [base64, mime] или [null, null].
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function fetchPreview(?string $previewUrl): array
    {
        if (! is_string($previewUrl) || $previewUrl === '') {
            return [null, null];
        }

        try {
            $response = $this->http->withOptions(['verify' => $this->hunyuanVerify()])->timeout(60)->get($previewUrl);
            if (! $response->successful()) {
                return [null, null];
            }

            $mime = $response->header('Content-Type') ?: 'image/png';

            return [base64_encode($response->body()), $mime];
        } catch (Throwable $e) {
            Log::warning('[moodboard.ai] model3d preview fetch failed', ['message' => $e->getMessage()]);

            return [null, null];
        }
    }

    /**
     * Значение опции verify для скачивания файлов Tencent (CA-бандл или bool).
     *
     * @return string|bool
     */
    private function hunyuanVerify(): string|bool
    {
        $config = (array) config('moodboard-ai.providers.hunyuan_3d');
        $caBundle = (string) ($config['ca_bundle'] ?? '');
        if ($caBundle !== '' && is_file($caBundle)) {
            return $caBundle;
        }

        return (bool) ($config['verify_ssl'] ?? true);
    }

    private function streamChat($client, array $payload): StreamedResponse
    {
        return response()->stream(function () use ($client, $payload): void {
            // PHP-FPM настройки на лету: длинный стрим не должен прерываться
            // по дефолтному max_execution_time. ignore_user_abort(false) важен,
            // чтобы цикл прервался, если клиент закрыл соединение.
            @set_time_limit(0);
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', '0');
            ignore_user_abort(false);

            // Снести любой активный output buffer — иначе chunk'и копятся,
            // пока буфер не переполнится, и фронт видит ответ пачкой.
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }

            $sse = new SseStreamWriter();

            try {
                $deltas = $client->chatStream($payload);
            } catch (AiHttpException $e) {
                Log::warning('[moodboard.ai] stream init error', ['status' => $e->status, 'message' => $e->getMessage()]);
                $sse->error($e->status.': '.$e->getMessage());
                $sse->done();
                return;
            } catch (Throwable $e) {
                Log::error('[moodboard.ai] stream init unexpected error', ['message' => $e->getMessage()]);
                $sse->error('500: '.$e->getMessage());
                $sse->done();
                return;
            }

            try {
                foreach ($deltas as $delta) {
                    if ($sse->isClosed()) {
                        return;
                    }
                    $sse->delta((string) $delta);
                }
                $sse->done();
            } catch (AiHttpException $e) {
                Log::warning('[moodboard.ai] stream delta error', ['status' => $e->status, 'message' => $e->getMessage()]);
                $sse->error($e->status.': '.$e->getMessage());
                $sse->done();
            } catch (Throwable $e) {
                Log::error('[moodboard.ai] stream delta unexpected error', ['message' => $e->getMessage()]);
                $sse->error($e->getMessage() ?: 'stream error');
                $sse->done();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function errorResponse(AiHttpException $e): JsonResponse
    {
        Log::warning('[moodboard.ai] provider error', [
            'status' => $e->status,
            'message' => $e->getMessage(),
            'details' => $e->details,
        ]);

        $body = ['error' => $e->getMessage()];
        if ($e->details !== null) {
            $body['details'] = $e->details;
        }

        return response()->json($body, $e->status);
    }
}
