<?php

/**
 * Конфиг AI-провайдеров для роутов /api/v2/ai/*.
 *
 * Перенесён из Node.js-заглушки (`server/src/config.js`). Контракт payload
 * и формат ответа /chat /image — те же, что и были у Express-прокси.
 *
 * Все ключи env префиксованы MOODBOARD_AI_*, чтобы не конфликтовать
 * с другими AI-интеграциями в родительском Laravel-проекте.
 *
 * Провайдер считается enabled, если у него заполнены минимально
 * необходимые ключи (api_key + folder_id для Yandex, api_key для DeepSeek).
 */

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP таймауты на вызов внешнего провайдера
    |--------------------------------------------------------------------------
    | Применяются к Guzzle (Http::timeout / connect_timeout).
    | Для chatStream/image (YandexART polling) PHP-FPM-ные max_execution_time
    | и nginx fastcgi_read_timeout — отдельная задача (см. docs).
    */
    'http' => [
        'connect_timeout' => (int) env('MOODBOARD_AI_HTTP_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('MOODBOARD_AI_HTTP_TIMEOUT', 300),
    ],

    'providers' => [

        'yandex' => [
            'api_key' => env('MOODBOARD_AI_YANDEX_API_KEY', ''),
            'folder_id' => env('MOODBOARD_AI_YANDEX_FOLDER_ID', ''),
            'default_model_uri' => env('MOODBOARD_AI_YANDEX_MODEL_URI', ''),
        ],

        'yandex_art' => [
            'api_key' => env('MOODBOARD_AI_YANDEX_API_KEY', ''),
            'folder_id' => env('MOODBOARD_AI_YANDEX_FOLDER_ID', ''),
            'art_model_uri' => env('MOODBOARD_AI_YANDEX_ART_MODEL_URI', ''),
            'poll_interval_ms' => (int) env('MOODBOARD_AI_YANDEX_ART_POLL_INTERVAL_MS', 2000),
            'timeout_ms' => (int) env('MOODBOARD_AI_YANDEX_ART_TIMEOUT_MS', 120000),
        ],

        'deepseek' => [
            'api_key' => env('MOODBOARD_AI_DEEPSEEK_API_KEY', ''),
            'default_model' => env('MOODBOARD_AI_DEEPSEEK_MODEL', 'deepseek-chat'),
        ],

    ],

];
