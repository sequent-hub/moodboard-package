<?php

namespace Futurello\MoodBoard\Services\Ai\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * HTTP-ошибка, унаследованная от формата заглушки server/src/utils/schema.js:HttpError.
 *
 * Контроллер ловит этот класс и отдаёт клиенту JSON
 * { error: <message>, details: <details> } со статусом $status.
 *
 * Метод render() гарантирует тот же чистый JSON, даже если исключение
 * брошено ВНЕ try/catch контроллера — например при валидации FormRequest
 * (AiImageRequest/AiModel3dRequest). Иначе Laravel в debug-режиме отдал бы
 * 500 со стектрейсом, и фронт показал бы «простыню» вместо аккуратной ошибки.
 *
 * Не использует Symfony HttpException, чтобы не плодить зависимостей
 * и держать формат ошибок 1:1 с Node-заглушкой (фронт-пакет moodboard
 * рассчитывает именно на такой контракт).
 */
class AiHttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly mixed $details = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function render(Request $request): JsonResponse
    {
        $body = ['error' => $this->getMessage()];
        if ($this->details !== null) {
            $body['details'] = $this->details;
        }

        return new JsonResponse($body, $this->status);
    }
}
