# MoodBoard Package

Laravel пакет для API функционала moodboard с поддержкой изображений и файлов.

## Описание

Этот пакет предоставляет полный API для работы с moodboard, включая:
- Создание, чтение, обновление и удаление moodboard
- Загрузка и управление изображениями
- Загрузка и управление файлами
- Статистика и аналитика

## Установка

### 1. Добавить в composer.json основного проекта:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/sequent-hub/moodboard-package.git"
        }
    ],
    "require": {
        "futurello/moodboard": "^1.1.9"
    }
}
```

### 2. Установить пакет:

```bash
composer require futurello/moodboard
```

### 3. Зарегистрировать Service Provider в `config/app.php`: (этот пункт устарел, для Laravel 11+ не нужен. Service Provider регистрируется автоматически благодаря Laravel Package Auto-Discovery)

```php
'providers' => [
    // ...
    Futurello\MoodBoard\Providers\MoodBoardServiceProvider::class,
],
```

### 4. Запустить миграции:

```bash
php artisan migrate
```

## API Endpoints

> Переезд на v2: см. карту `Было/Стало` в `docs/API_V2_WAS_BECAME.md`.
>
> Важно: модель `Image` удалена из пакета, а таблица `images` не используется в runtime.
> Хранение изображений выполняется во внешнем object storage (S3/CDN), в состоянии доски хранится `src`.
> Важно: таблица `files` не используется в runtime. Для файлов актуален upload-only поток.

### Актуальные v2 endpoints

#### MoodBoard (v2)

- `POST /api/v2/moodboard/metadata/save` - Сохранение метаданных доски
- `POST /api/v2/moodboard/history/save` - Сохранение состояния (истории)
- `GET /api/v2/moodboard/{moodboard_id}/{version?}` - Загрузка актуальной или конкретной версии

#### Images (v2)

- `POST /api/v2/images/upload` - Загрузка изображения

## Source Of Truth (Images)

- Рендер изображения на доске работает только через `state.objects[].src`.
- SQL-таблица `images` и модель `Image` не участвуют в runtime-потоке.
- `GET /api/v2/images/{id}` и `GET /api/v2/images/{id}/download` удалены из `v2` контракта.
- При удалении объекта изображения с доски обновляется только `state`; физический файл в object storage не удаляется.
- История `moodboard_history` append-only: состояния не обновляются и не удаляются.

### CDN URL Normalization

- После `POST /api/v2/images/upload` URL формируется из object storage.
- Если задан `MOODBOARD_IMAGE_CDN_BASE_URL`, backend нормализует URL к CDN-формату.
- При `POST /api/v2/moodboard/history/save` `image.src` дополнительно нормализуется к CDN-URL (если возможно извлечь object path).
- `data:` и `blob:` источники не переписываются.

### Logging Checklist

1. Проверить upload лог `Image uploaded to object storage` (`path`, `storage_url`, `response_url`).
2. Проверить `response_url` в ответе `POST /api/v2/images/upload`.
3. Проверить лог `Moodboard history image src normalization` (`image_objects_total`, `image_objects_with_src`, `image_objects_normalized_to_cdn`).
4. Проверить `GET /api/v2/moodboard/{moodboard_id}` и наличие `state.objects[].src`.
5. Проверить на фронте рендер объекта `type: "image"` по `src` после reload.

#### Files (v2)

- `POST /api/v2/files/upload` - Загрузка файла
- Legacy (история изменений, отключено): `GET /api/v2/files/{fileId}`, `GET /api/v2/files/{fileId}/download`, `PUT /api/v2/files/{fileId}`, `DELETE /api/v2/files/{fileId}`

### LEGACY (было, сохранено для истории)

### MoodBoard

- `POST /api/moodboard/save` - Сохранение данных доски
- `GET /api/moodboard/load/{boardId}` - Загрузка данных доски
- `GET /api/moodboard/{boardId}` - Загрузка данных доски (альтернативный)
- `GET /api/moodboard/list` - Список всех досок
- `GET /api/moodboard/show/{boardId}` - Показать доску
- `DELETE /api/moodboard/delete/{boardId}` - Удаление доски
- `POST /api/moodboard/duplicate/{boardId}` - Дублирование доски
- `GET /api/moodboard/{boardId}/images/stats` - Статистика изображений

### Images

- `POST /api/images/upload` - Загрузка изображения
- `GET /api/images/{id}` - Информация об изображении
- `GET /api/images/{id}/file` - Получение файла изображения
- `DELETE /api/images/{id}` - Удаление изображения
- `GET /api/images/` - Список всех изображений
- `POST /api/images/bulk-delete` - Массовое удаление

### Files

- `POST /api/files/upload` - Загрузка файла
- `GET /api/files/{id}` - Информация о файле
- `PUT /api/files/{id}` - Обновление файла
- `GET /api/files/{id}/download` - Скачивание файла
- `DELETE /api/files/{id}` - Удаление файла

## Модели

### MoodBoard
- `board_id` - Уникальный публичный ID доски
- `name` - Название доски
- `description` - Описание доски
- `data` - JSON данные доски (объекты, настройки)
- `settings` - JSON настройки доски
- `version` - Версия для конфликтов
- `last_saved_at` - Время последнего сохранения

### Image
- `id` - UUID изображения
- `name` - Название изображения
- `original_name` - Оригинальное имя файла
- `path` - Путь к файлу
- `mime_type` - MIME тип
- `size` - Размер файла
- `width` - Ширина изображения
- `height` - Высота изображения
- `hash` - MD5 хеш для дедупликации

### File
- `id` - ID файла
- `name` - Оригинальное имя файла
- `filename` - Имя файла на диске
- `path` - Путь к файлу
- `mime_type` - MIME тип
- `size` - Размер файла
- `extension` - Расширение файла
- `hash` - Хеш для дедупликации

## Использование

### Создание moodboard:

```php
use Futurello\MoodBoard\Models\MoodBoard;

$moodboard = MoodBoard::create([
    'board_id' => 'unique-id',
    'name' => 'My Board',
    'description' => 'Description',
    'data' => ['objects' => []],
    'settings' => MoodBoard::getDefaultSettings()
]);
```

### Загрузка изображения:

```php
use Futurello\MoodBoard\Models\Image;

$image = Image::create([
    'name' => 'My Image',
    'original_name' => 'image.jpg',
    'path' => 'images/2025/08/image.jpg',
    'mime_type' => 'image/jpeg',
    'size' => 1024,
    'width' => 800,
    'height' => 600,
    'hash' => 'md5-hash'
]);
```

## Требования

- PHP >= 8.2
- Laravel >= 10.0
- Intervention Image (для работы с изображениями)

## Лицензия

MIT License
