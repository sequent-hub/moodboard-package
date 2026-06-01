<?php

namespace Futurello\MoodBoard\Services\Ai\Exceptions;

use RuntimeException;
use Throwable;

/**
 * HTTP-ошибка, унаследованная от формата заглушки server/src/utils/schema.js:HttpError.
 *
 * Контроллер ловит этот класс и отдаёт клиенту JSON
 * { error: <message>, details: <details> } со статусом $status.
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
}
