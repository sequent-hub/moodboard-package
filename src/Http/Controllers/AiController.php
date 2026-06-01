<?php

namespace Futurello\MoodBoard\Http\Controllers;

use Futurello\MoodBoard\Http\Requests\AiChatRequest;
use Futurello\MoodBoard\Http\Requests\AiImageRequest;
use Futurello\MoodBoard\Services\Ai\Exceptions\AiHttpException;
use Futurello\MoodBoard\Services\Ai\Support\ProviderRegistry;
use Futurello\MoodBoard\Services\Ai\Support\SseStreamWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
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
    public function __construct(private readonly ProviderRegistry $registry)
    {
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

    public function image(AiImageRequest $request): JsonResponse
    {
        try {
            $client = $this->registry->image('yandex-art');
            $payload = $request->normalized();
            $result = $client->generateImage($payload);
        } catch (AiHttpException $e) {
            return $this->errorResponse($e);
        } catch (Throwable $e) {
            return $this->errorResponse(new AiHttpException(500, 'Internal server error'));
        }

        return response()->json($result);
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
                $sse->error($e->status.': '.$e->getMessage());
                $sse->done();
                return;
            } catch (Throwable $e) {
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
                $sse->error($e->status.': '.$e->getMessage());
                $sse->done();
            } catch (Throwable $e) {
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
        $body = ['error' => $e->getMessage()];
        if ($e->details !== null) {
            $body['details'] = $e->details;
        }

        return response()->json($body, $e->status);
    }
}
