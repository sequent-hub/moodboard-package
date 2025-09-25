# Логирование миграций MoodBoard

## Обзор

Пакет `futurello/moodboard` теперь включает расширенное логирование миграций для диагностики проблем при установке и выполнении миграций.

## Что логируется

### 1. События уровня пакета
- 🚀 **Начало выполнения** всех миграций пакета
- ✅ **Завершение выполнения** всех миграций пакета
- 📊 **Использование памяти** и временные метки

### 2. События уровня отдельных миграций
- ⚡ **Начало выполнения** конкретной миграции
- ✅ **Успешное завершение** миграции
- ❌ **Ошибки выполнения** с полной трассировкой стека
- 📊 **Информация о структуре таблиц** после создания

### 3. Детальная информация об ошибках
- Сообщение об ошибке
- Код ошибки
- Файл и строка, где произошла ошибка
- Полная трассировка стека
- Состояние таблицы (существует/не существует)
- Использование памяти

## Как просматривать логи

### Просмотр логов Laravel
```bash
# Основной лог Laravel
tail -f storage/logs/laravel.log

# Фильтр только логов MoodBoard
tail -f storage/logs/laravel.log | grep "MOODBOARD"
```

### Поиск конкретных событий
```bash
# Поиск ошибок миграций
grep "❌.*MOODBOARD MIGRATION" storage/logs/laravel.log

# Поиск успешных миграций
grep "✅.*MOODBOARD MIGRATION" storage/logs/laravel.log

# Поиск начала выполнения миграций
grep "🚀.*MOODBOARD" storage/logs/laravel.log
```

## Примеры логов

### Успешное выполнение миграции
```
[2025-09-25 12:00:00] local.INFO: 🚀 [MOODBOARD MIGRATION] Starting create table {"migration":"2025_08_25_085130_create_images_table","table":"images","action":"create table","timestamp":"2025-09-25T12:00:00.000000Z","memory_usage":2097152}

[2025-09-25 12:00:01] local.INFO: 📊 [MOODBOARD MIGRATION] Table structure {"table":"images","columns":["id","name","original_name","path","mime_type","size","width","height","hash","created_at","updated_at"],"column_count":11,"timestamp":"2025-09-25T12:00:01.000000Z"}

[2025-09-25 12:00:01] local.INFO: ✅ [MOODBOARD MIGRATION] Successfully completed create table {"migration":"2025_08_25_085130_create_images_table","table":"images","action":"create table","timestamp":"2025-09-25T12:00:01.000000Z","memory_usage":2097152,"table_exists":true}
```

### Ошибка выполнения миграции
```
[2025-09-25 12:00:00] local.ERROR: ❌ [MOODBOARD MIGRATION] Failed create table {"migration":"2025_08_25_085130_create_images_table","table":"images","action":"create table","timestamp":"2025-09-25T12:00:00.000000Z","error":"SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'images' already exists","error_code":1050,"error_file":"/path/to/migration/file.php","error_line":15,"memory_usage":2097152,"table_exists":true,"trace":"..."}
```

## Диагностика проблем

### Проблема: Таблица не создается
1. **Проверьте логи** на наличие ошибок для конкретной таблицы:
   ```bash
   grep "images.*❌" storage/logs/laravel.log
   ```

2. **Анализируйте ошибку** - частые причины:
   - Таблица уже существует
   - Недостаточно прав доступа к БД
   - Синтаксическая ошибка в SQL

### Проблема: Миграция не запускается
1. **Проверьте, выполняется ли пакетная миграция**:
   ```bash
   grep "MOODBOARD PACKAGE.*Starting migrations batch" storage/logs/laravel.log
   ```

2. **Если пакетная миграция не найдена** - проблема в регистрации пакета:
   - Проверьте `config/app.php` (providers)
   - Выполните `php artisan package:discover`

### Проблема: Частичное выполнение миграций
1. **Найдите последнюю успешную миграцию**:
   ```bash
   grep "✅.*MOODBOARD MIGRATION" storage/logs/laravel.log | tail -1
   ```

2. **Найдите первую неудачную миграцию**:
   ```bash
   grep "❌.*MOODBOARD MIGRATION" storage/logs/laravel.log | head -1
   ```

## Отключение логирования

Если нужно отключить подробное логирование миграций, закомментируйте в `MoodBoardServiceProvider`:

```php
// $this->registerMigrationEventListeners();
```

## Настройка уровня логирования

Для изменения уровня логирования отредактируйте `config/logging.php`:

```php
'channels' => [
    'single' => [
        'driver' => 'single',
        'path' => storage_path('logs/laravel.log'),
        'level' => env('LOG_LEVEL', 'debug'), // 'debug', 'info', 'warning', 'error'
    ],
],
```

## Мониторинг в продакшене

Для продакшен-среды рекомендуется:

1. **Использовать отдельный канал логирования**:
   ```php
   Log::channel('migrations')->info(...);
   ```

2. **Настроить ротацию логов**:
   ```php
   'channels' => [
       'migrations' => [
           'driver' => 'daily',
           'path' => storage_path('logs/migrations.log'),
           'level' => 'info',
           'days' => 14,
       ],
   ],
   ```

3. **Интеграция с системами мониторинга** (Sentry, Bugsnag, etc.)
