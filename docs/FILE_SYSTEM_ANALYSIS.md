# ОПИСАНИЕ МЕХАНИЗМА СОХРАНЕНИЯ И ПОЛУЧЕНИЯ ФАЙЛОВ В ПАКЕТЕ MOODBOARD

## 1. АРХИТЕКТУРА СИСТЕМЫ ФАЙЛОВ

Пакет moodboard использует **двухуровневую архитектуру** для работы с файлами:

### **Уровень 1: Изображения (Images)**
- **Модель**: `Futurello\MoodBoard\Models\Image`
- **Контроллер**: `Futurello\MoodBoard\Http\Controllers\ImageController`
- **Таблица**: `images`
- **Назначение**: Специально для изображений, используемых на досках

### **Уровень 2: Общие файлы (Files)**  
- **Модель**: `Futurello\MoodBoard\Models\File`
- **Контроллер**: `Futurello\MoodBoard\Http\Controllers\FileController`
- **Таблица**: `files`
- **Назначение**: Общие файлы (документы, архивы и т.д.)

## 2. МЕХАНИЗМ СОХРАНЕНИЯ ИЗОБРАЖЕНИЙ

### **Шаг 1: Загрузка файла**
```php
POST /api/images/upload
```

**Процесс:**
1. **Валидация** - проверка типа файла, размера (макс 10MB)
2. **Дедупликация** - создание MD5 хеша для проверки дубликатов
3. **Генерация имени** - `time() + random(10) + extension`
4. **Сохранение в папку** - `storage/app/images/YYYY/MM/filename`
5. **Запись в БД** - создание записи в таблице `images`

### **Шаг 2: Структура записи в БД**
```sql
images:
- id (UUID)
- name (пользовательское имя)
- original_name (исходное имя файла)
- path (путь к файлу: images/2025/01/filename.jpg)
- mime_type
- size
- width, height
- hash (MD5 для дедупликации)
```

### **Шаг 3: Интеграция с MoodBoard**
1. **Сохранение на доске** - в `boardData.objects` сохраняется только `imageId`
2. **Очистка данных** - метод `cleanImageData()` удаляет base64 и src
3. **Восстановление URL** - метод `restoreImageUrls()` восстанавливает ссылки при загрузке

## 3. МЕХАНИЗМ ПОЛУЧЕНИЯ ФАЙЛОВ

### **Получение изображения:**
```php
GET /api/images/{id}/file
```

**Процесс:**
1. Поиск записи в БД по ID
2. Проверка существования файла: `Storage::exists($image->path)`
3. Возврат файла: `Storage::response($image->path)`

### **Генерация URL:**
```php
// В контроллере
private function getImageUrl(string $imageId): string
{
    try {
        return route('images.file', $imageId);
    } catch (\Exception $e) {
        return url("/api/images/{$imageId}/file");
    }
}
```

## 4. ВОЗМОЖНЫЕ ПРОБЛЕМЫ ИНТЕГРАЦИИ

### **ПРОБЛЕМА #1: Конфликт Storage дисков**
**Описание:** Пакет использует дефолтный Storage диск, но в основном приложении может быть настроен другой диск.

**Симптомы:**
- Файл сохраняется в одном месте, ищется в другом
- Ошибка "файл не найден на диске" при сохранении

**Решение:**
```php
// В ImageController::upload()
Storage::disk('local')->put($path, file_get_contents($file));

// В ImageController::file() 
if (!Storage::disk('local')->exists($image->path)) {
    // ошибка
}
```

### **ПРОБЛЕМА #2: Несовместимость путей**
**Описание:** Пакет сохраняет относительные пути (`images/2025/01/file.jpg`), но основное приложение может ожидать абсолютные.

**Симптомы:**
- Запись в БД создается
- Файл физически существует  
- При обращении к файлу - ошибка 404

**Диагностика:**
```php
// Проверить в логах:
Log::info('Image path: ' . $image->path);
Log::info('Storage exists: ' . Storage::exists($image->path));
Log::info('Full path: ' . Storage::path($image->path));
```

### **ПРОБЛЕМА #3: Конфликт маршрутов**
**Описание:** Именованный маршрут `images.file` может не регистрироваться в основном приложении.

**Симптомы:**
- В логах: "Route 'images.file' not found"
- URL генерируется как fallback

**Решение:**
```php
// Проверить регистрацию маршрутов в MoodBoardServiceProvider
public function boot()
{
    $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
}
```

### **ПРОБЛЕМА #4: Конфликт моделей**
**Описание:** В основном приложении есть своя модель `App\Models\Image`, а пакет использует `Futurello\MoodBoard\Models\Image`.

**Симптомы:**
- Ошибки при сохранении в БД
- Неправильная таблица используется

**Решение:** Убедиться что в пакете используются правильные namespace'ы.

### **ПРОБЛЕМА #5: Миграции не выполнены**
**Описание:** Таблицы `images` или `files` не созданы в основном приложении.

**Симптомы:**
- SQL ошибки при сохранении
- "Table doesn't exist"

**Решение:**
```bash
php artisan vendor:publish --provider="Futurello\MoodBoard\Providers\MoodBoardServiceProvider"
php artisan migrate
```

## 5. ДИАГНОСТИКА ПРОБЛЕМ

### **Шаг 1: Проверка Storage конфигурации**
```php
// Добавить в ImageController::upload()
Log::info('Storage config', [
    'default_disk' => config('filesystems.default'),
    'disk_config' => config('filesystems.disks.local'),
    'storage_path' => storage_path('app')
]);
```

### **Шаг 2: Проверка сохранения файла**
```php
// После Storage::put()
Log::info('File save result', [
    'path' => $path,
    'exists_after_save' => Storage::exists($path),
    'full_path' => Storage::path($path),
    'file_size' => Storage::size($path)
]);
```

### **Шаг 3: Проверка при загрузке**
```php
// В restoreImageUrls()
Log::info('Image restore', [
    'imageId' => $object['imageId'],
    'image_found' => $image ? 'yes' : 'no',
    'file_exists' => $image ? Storage::exists($image->path) : 'no image',
    'generated_url' => $image ? $this->getImageUrl($image->id) : 'no url'
]);
```

## 6. РЕКОМЕНДАЦИИ ПО ИСПРАВЛЕНИЮ

### **Для разработчика основного приложения:**

1. **Проверить Storage конфигурацию:**
   ```php
   // config/filesystems.php
   'default' => env('FILESYSTEM_DISK', 'local'),
   ```

2. **Убедиться в правильности путей:**
   ```php
   // Проверить что storage/app доступна для записи
   ls -la storage/app/
   ```

3. **Выполнить миграции пакета:**
   ```bash
   php artisan vendor:publish --tag=moodboard-migrations
   php artisan migrate
   ```

4. **Создать симлинк для публичного доступа:**
   ```bash
   php artisan storage:link
   ```

5. **Добавить отладочное логирование** в критических местах

### **Наиболее вероятная причина проблемы:**
Судя по описанию ("файл добавляется, запись в БД появляется, но при сохранении ошибка"), проблема скорее всего в **конфликте Storage дисков** или **неправильных путях к файлам**. Пакет сохраняет файл на одном диске, а при восстановлении ищет на другом.

## 7. КЛЮЧЕВЫЕ МОМЕНТЫ ДЛЯ ОТЛАДКИ

1. **Двухуровневая архитектура**: Images (для изображений на досках) и Files (общие файлы)
2. **Механизм сохранения**: файл → storage/app/images/YYYY/MM/ → запись в БД с относительным путем
3. **Механизм восстановления**: поиск по imageId → проверка Storage::exists() → генерация URL
4. **Основные проблемы**: конфликт дисков, неправильные пути, отсутствие маршрутов, несовместимость моделей
5. **Диагностика**: добавить логирование в ImageController::upload() и MoodBoardController::restoreImageUrls()

## 8. ТЕХНИЧЕСКАЯ СХЕМА ПОТОКА ДАННЫХ

```
Загрузка изображения:
Frontend → POST /api/images/upload → ImageController::upload() → Storage::put() → DB::insert(images)

Сохранение доски:
Frontend → POST /api/moodboard/save → MoodBoardController::save() → cleanImageData() → DB::update(moodboards)

Загрузка доски:
Frontend → GET /api/moodboard/load/{id} → MoodBoardController::load() → restoreImageUrls() → Response

Получение файла:
Frontend → GET /api/images/{id}/file → ImageController::file() → Storage::response()
```

---
**Дата создания:** 2025-01-15  
**Версия пакета:** 1.0.4  
**Статус:** Анализ проблем интеграции
