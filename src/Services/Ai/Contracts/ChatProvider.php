<?php

namespace Futurello\MoodBoard\Services\Ai\Contracts;

/**
 * Абстракция чат-провайдера: и DeepSeek, и YandexGPT.
 *
 * payload — нормализованный массив, полученный из AiChatRequest:
 * [
 *   'messages'    => [ ['role' => 'user', 'content' => '...'], ... ],
 *   'stream'      => bool,
 *   'temperature' => float|null,
 *   'maxTokens'   => int|null,
 *   'model'       => string|null,
 * ]
 *
 * chat()       — синхронный вызов, возвращает ['text' => '...'].
 * chatStream() — стриминг, возвращает iterable<string> с дельтами текста.
 *                Реализация может бросить AiHttpException до начала итерации
 *                (если HTTP-запрос к провайдеру упал) или во время неё.
 */
interface ChatProvider
{
    public function isEnabled(): bool;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{text: string}
     */
    public function chat(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return iterable<string>
     */
    public function chatStream(array $payload): iterable;
}
