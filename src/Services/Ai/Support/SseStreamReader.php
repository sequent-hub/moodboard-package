<?php

namespace Futurello\MoodBoard\Services\Ai\Support;

use Psr\Http\Message\StreamInterface;

/**
 * Stream-парсер ответа от провайдера (DeepSeek SSE + Yandex JSONL).
 *
 * Порт server/src/utils/sseParser.js.
 *
 * На вход — PSR-7 StreamInterface (тело Guzzle Response с опцией stream=true).
 * На выход — итерируемый generator событий ['data' => string].
 *
 *  - DeepSeek отдаёт классический SSE:
 *      data: {...}\n
 *      \n
 *    Финальный маркер "data: [DONE]".
 *
 *  - YandexGPT отдаёт чанки как поток JSON-объектов (по строке на объект),
 *    без "data: " префикса. Парсер опознаёт строку, начинающуюся с "{",
 *    и отдаёт её "как есть" в поле data.
 */
class SseStreamReader
{
    /**
     * @return iterable<array{data: string}>
     */
    public static function events(StreamInterface $body): iterable
    {
        $buffer = '';

        while (! $body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            while (($idx = strpos($buffer, "\n")) !== false) {
                $rawLine = substr($buffer, 0, $idx);
                $buffer = substr($buffer, $idx + 1);

                $trimmed = trim($rawLine);
                if ($trimmed === '') {
                    continue;
                }

                // Yandex JSONL: строка-объект целиком.
                if (str_starts_with($trimmed, '{')) {
                    yield ['data' => $trimmed];

                    continue;
                }

                $event = self::parseSseLine($rawLine);
                if ($event !== null) {
                    yield $event;
                }
            }
        }

        if ($buffer !== '') {
            $event = self::parseSseLine($buffer);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    /**
     * @return array{data: string}|null
     */
    private static function parseSseLine(string $raw): ?array
    {
        $lines = preg_split('/\r?\n/', $raw) ?: [];
        $dataParts = [];
        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }
            if (str_starts_with($line, 'data:')) {
                $dataParts[] = ltrim(substr($line, 5));
            }
        }

        if ($dataParts === []) {
            return null;
        }

        return ['data' => implode("\n", $dataParts)];
    }
}
