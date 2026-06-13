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

        'openai_image' => [
            'api_key' => env('OPENAI_API_KEY', ''),
            'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1.5'),
        ],

        // Tencent Hunyuan To 3D (TencentCloud API 3.0, TC3-HMAC).
        // ТОЛЬКО для локального теста: прямой вызов Tencent, минуя «Трубу»/«Бухгалтера».
        // Подпись требует ПАРУ secret_id + secret_key (одного ключа недостаточно).
        'hunyuan_3d' => [
            'secret_id' => env('MOODBOARD_AI_HUNYUAN3D_SECRET_ID', ''),
            'secret_key' => env('MOODBOARD_AI_HUNYUAN3D_SECRET_KEY', ''),
            'host' => env('MOODBOARD_AI_HUNYUAN3D_HOST', 'hunyuan.intl.tencentcloudapi.com'),
            'service' => env('MOODBOARD_AI_HUNYUAN3D_SERVICE', 'hunyuan'),
            'region' => env('MOODBOARD_AI_HUNYUAN3D_REGION', 'ap-guangzhou'),
            'version' => env('MOODBOARD_AI_HUNYUAN3D_VERSION', '2023-09-01'),
            'submit_action' => env('MOODBOARD_AI_HUNYUAN3D_SUBMIT_ACTION', 'SubmitHunyuanTo3DProJob'),
            'query_action' => env('MOODBOARD_AI_HUNYUAN3D_QUERY_ACTION', 'QueryHunyuanTo3DProJob'),
            // Путь к CA-бандлу (cacert.pem) для проверки SSL. Нужен на Windows-PHP,
            // где curl.cainfo не настроен. Если пусто — verify=true (системный стор).
            // verify_ssl=false отключает проверку (НЕ для прода).
            'ca_bundle' => env('MOODBOARD_AI_HUNYUAN3D_CA_BUNDLE', ''),
            'verify_ssl' => filter_var(env('MOODBOARD_AI_HUNYUAN3D_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
        ],

    ],

];
