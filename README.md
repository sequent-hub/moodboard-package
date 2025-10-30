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
            "type": "path",
            "url": "https://github.com/sequent-hub/moodboard-package.git"
        }
    ],
    "require": {
        "futurello/moodboard": "*"
    }
}
```

### 2. Установить пакет:

```bash
composer require futurello/moodboard
```

### 3. Зарегистрировать Service Provider в `config/app.php`:

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
- `POST /api/images/cleanup` - Очистка неиспользуемых

### Files

- `POST /api/files/upload` - Загрузка файла
- `GET /api/files/{id}` - Информация о файле
- `PUT /api/files/{id}` - Обновление файла
- `GET /api/files/{id}/download` - Скачивание файла
- `DELETE /api/files/{id}` - Удаление файла
- `POST /api/files/cleanup` - Очистка неиспользуемых

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
