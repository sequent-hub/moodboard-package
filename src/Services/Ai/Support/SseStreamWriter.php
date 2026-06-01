<?php

namespace Futurello\MoodBoard\Services\Ai\Support;

/**
 * Writer SSE-потока для клиента.
 *
 * Унифицированный формат событий (1:1 с server/src/utils/sseWriter.js):
 *
 *   data: {"delta":"..."}\n\n
 *   data: {"delta":"..."}\n\n
 *   data: [DONE]\n\n
 *
 * Ошибки во время стрима:
 *
 *   event: error\n
 *   data: {"error":"..."}\n\n
 *
 * Использование в response()->stream() контроллера:
 *
 *   $sse = new SseStreamWriter();
 *   foreach ($deltas as $delta) {
 *       if ($sse->isClosed()) { break; }
 *       $sse->delta($delta);
 *   }
 *   $sse->done();
 *
 * ВАЖНО: для реального построчного flush нужны (вне кода):
 *   - nginx:   proxy_buffering off; fastcgi_buffering off;
 *   - php-fpm: output_buffering = Off
 *   - запрос:  set_time_limit(0); ignore_user_abort(false);
 */
class SseStreamWriter
{
    private bool $closed = false;

    public function isClosed(): bool
    {
        if ($this->closed) {
            return true;
        }

        if (connection_aborted()) {
            $this->closed = true;
        }

        return $this->closed;
    }

    public function delta(string $text): void
    {
        if ($this->isClosed() || $text === '') {
            return;
        }

        $this->write('data: '.json_encode(['delta' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n");
    }

    public function error(string $message): void
    {
        if ($this->isClosed()) {
            return;
        }

        $payload = json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->write("event: error\ndata: ".$payload."\n\n");
    }

    public function done(): void
    {
        if ($this->isClosed()) {
            return;
        }

        $this->write("data: [DONE]\n\n");
        $this->closed = true;
    }

    private function write(string $chunk): void
    {
        echo $chunk;

        // Если включён output_buffering — обнулить активный буфер,
        // чтобы chunk ушёл сразу в php-fpm → nginx → клиент.
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
