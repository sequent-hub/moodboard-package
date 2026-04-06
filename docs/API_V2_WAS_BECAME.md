# API v2 Migration: Было/Стало

Этот документ фиксирует переезд API на `v2` без потери трассировки legacy путей.

> Обновление контракта: модель `Image` удалена, таблица `images` не участвует в runtime-потоке.
> Изображения хранятся во внешнем object storage, а доска работает через `src`.

## Общие правила

- Legacy маршруты `/api/...` считаются историческими.
- Актуальные маршруты идут через `/api/v2/...`.
- Часть legacy-функций в `v2` пока зарезервирована как заглушки и возвращает `501`.

## Moodboard

- Было: `POST /api/moodboard/save`
- Стало: `POST /api/v2/moodboard/metadata/save` + `POST /api/v2/moodboard/history/save`

- Было: `GET /api/moodboard/load/{boardId}`
- Стало: `GET /api/v2/moodboard/{moodboard_id}/{version?}`

- Было: `GET /api/moodboard/{boardId}`
- Стало: `GET /api/v2/moodboard/{moodboard_id}/{version?}`

- Было: `GET /api/moodboard/list`
- Стало: `GET /api/v2/moodboard/list` (заглушка `501`)

- Было: `GET /api/moodboard/show/{boardId}`
- Стало: `GET /api/v2/moodboard/show/{boardId}` (заглушка `501`)

- Было: `DELETE /api/moodboard/delete/{boardId}`
- Стало: `DELETE /api/v2/moodboard/delete/{boardId}` (заглушка `501`)

- Было: `POST /api/moodboard/duplicate/{boardId}`
- Стало: `POST /api/v2/moodboard/duplicate/{boardId}` (заглушка `501`)

- Было: `GET /api/moodboard/{boardId}/images/stats`
- Стало: `GET /api/v2/moodboard/{boardId}/images/stats` (заглушка `501`)

## Images

- Было: `POST /api/images/upload`
- Стало: `POST /api/v2/images/upload`

- Было: `GET /api/images/{id}`
- Стало: удалено из `v2` контракта

- Было: `GET /api/images/{id}/file`
- Стало: удалено из `v2` контракта

- Было: `GET /api/images/`
- Стало: `GET /api/v2/images/` (заглушка `501`)

- Было: `POST /api/images/bulk-delete`
- Стало: `POST /api/v2/images/bulk-delete` (заглушка `501`)

- Было: `DELETE /api/images/{id}`
- Стало: `DELETE /api/v2/images/{id}` (заглушка `501`)

## Files

- Было: `POST /api/files/upload`
- Стало: `POST /api/v2/files/upload`

- Было: `GET /api/files/{id}`
- Стало: `GET /api/v2/files/{fileId}`

- Было: `GET /api/files/{id}/download`
- Стало: `GET /api/v2/files/{fileId}/download`

- Было: `PUT /api/files/{id}`
- Стало: `PUT /api/v2/files/{fileId}` (заглушка `501`)

- Было: `DELETE /api/files/{id}`
- Стало: `DELETE /api/v2/files/{fileId}` (заглушка `501`)
