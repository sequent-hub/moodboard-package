# Исправление конфликта дисков в FileController

## Описание проблемы

В версии 1.0.4 пакета `futurello/moodboard` была обнаружена критическая ошибка конфликта дисков при работе с файлами:

- **Файлы сохранялись** на диск `public` (`storage/app/public/files/`)
- **Файлы искались** на дефолтном диске `local` (`storage/app/files/`)

Это приводило к ошибке "Файл не найден на диске" при попытке скачать файлы через API.

## Исправленные файлы

### 1. `src/Http/Controllers/FileController.php`

**Метод `download()` (строки 179, 186):**

```php
// БЫЛО:
if (!Storage::exists($file->path)) {
    // ...
}
return Storage::download($file->path, $file->name);

// СТАЛО:
if (!Storage::disk('public')->exists($file->path)) {
    // ...
}
return Storage::disk('public')->download($file->path, $file->name);
```

### 2. `src/Models/File.php`

**Метод `getUrlAttribute()` (строка 32):**

```php
// БЫЛО:
return Storage::url($this->path);

// СТАЛО:
return Storage::disk('public')->url($this->path);
```

**Метод `boot()` - deleting callback (строки 68-69):**

```php
// БЫЛО:
if (Storage::exists($file->path)) {
    Storage::delete($file->path);
}

// СТАЛО:
if (Storage::disk('public')->exists($file->path)) {
    Storage::disk('public')->delete($file->path);
}
```

### 3. `composer.json`

**Обновлена версия пакета:**

```json
// БЫЛО:
"version": "1.0.4",

// СТАЛО:
"version": "1.0.5",
```

## Логика исправления

### Файлы (FileController + File model):
- **Сохранение**: `Storage::disk('public')->storeAs('files', $filename)` ✅ (уже было правильно)
- **Проверка**: `Storage::disk('public')->exists($file->path)` ✅ (исправлено)
- **Скачивание**: `Storage::disk('public')->download($file->path, $file->name)` ✅ (исправлено)
- **URL**: `Storage::disk('public')->url($this->path)` ✅ (исправлено)
- **Удаление**: `Storage::disk('public')->delete($file->path)` ✅ (исправлено)

### Изображения (ImageController + Image model):
- **Сохранение**: `Storage::put($path, $content)` (дефолтный диск `local`) ✅ (не изменено)
- **Проверка**: `Storage::exists($image->path)` (дефолтный диск `local`) ✅ (не изменено)
- **Отдача**: `Storage::response($image->path, ...)` (дефолтный диск `local`) ✅ (не изменено)

## Результат

- ✅ Исправлена ошибка "Файл не найден на диске" при скачивании файлов
- ✅ Все операции с файлами теперь используют единый диск `public`
- ✅ Сохранена совместимость с существующими изображениями (диск `local`)
- ✅ Обновлена версия пакета до 1.0.5

## Тестирование

### Проверка файлов:
```bash
# Проверить существование файлов на диске public
ls -la storage/app/public/files/

# Проверить API скачивания
curl http://your-domain/api/files/{id}/download
```

### Проверка изображений (не изменилось):
```bash
# Проверить существование изображений на диске local
ls -la storage/app/images/

# Проверить API изображений
curl http://your-domain/api/images/{id}/file
```

## Обновление в проектах

После релиза версии 1.0.5:

1. **Обновить пакет в проекте:**
   ```bash
   composer update futurello/moodboard
   ```

2. **Удалить временные переопределения** (если были созданы):
   - `app/Http/Controllers/Api/FileController.php` ❌ (удалить)
   - Переопределенные маршруты в `routes/api.php` ❌ (удалить)

3. **Проверить работу API файлов**

---

**Дата исправления**: 15 января 2025  
**Версия**: 1.0.5  
**Статус**: Исправлено ✅
