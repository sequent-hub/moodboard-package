# Контракт API MoodBoard (бэкенд ↔ фронтенд)

Официальный формат данных, согласованный с фронтендом. Бэкенд подстраивается под этот контракт.

## Save — POST /api/moodboard/save

Фронтенд использует только одну схему:

| Ключ | Обязательный | Описание |
|------|--------------|----------|
| `boardId` | да | ID доски |
| `boardData` | иногда | `{ objects, name, description }` |
| `settings` | иногда | `{ backgroundColor, grid, zoom, pan, canvas }` |

**Пример payload (записка с поворотом):**

```json
{
  "boardId": "board_abc123",
  "boardData": {
    "objects": [
      {
        "id": "obj_17480001",
        "type": "note",
        "position": { "x": 150, "y": 300 },
        "width": 250,
        "height": 250,
        "properties": {
          "content": "Текст записки",
          "fontSize": 32,
          "fontFamily": "Caveat, Arial, cursive",
          "backgroundColor": 16775620,
          "borderColor": 16361509,
          "textColor": 1710618
        },
        "transform": {
          "pivotCompensated": false,
          "rotation": 45
        },
        "created": "2026-03-02T15:00:00.000Z"
      }
    ],
    "name": "My Board",
    "description": null
  },
  "settings": {
    "backgroundColor": "#F5F5F5",
    "grid": { "type": "dot", "size": 20, "visible": true, "color": "#E0E0E0" },
    "zoom": { "min": 0.1, "max": 5.0, "default": 1.0, "current": 1.2 },
    "pan": { "x": -100, "y": -50 },
    "canvas": { "width": 1920, "height": 1080 }
  }
}
```

## Load — GET /api/moodboard/load/{boardId} или GET /api/moodboard/{boardId}

**Ожидаемый ответ:**

```json
{
  "success": true,
  "data": {
    "objects": [ ... ],
    "settings": { ... }
  }
}
```

## Объекты (objects)

**Обязательные поля:** `id`, `type`, `position`  
**Опциональные:** `width`, `height`, `properties`, `transform`, `created`, `imageId`, `fileId`

**transform** — критично:
- Бэкенд **не должен** удалять или фильтровать `transform`.
- Может быть `{ pivotCompensated?: boolean, rotation?: number }`.
- Если `rotation` отсутствует → фронт считает 0°.
- Бэкенд отдаёт данные as-is, без добавления дефолтов.

## Типы объектов

`note`, `text`, `image`, `frame`, `shape`, `drawing`, `emoji`, `comment`, `file`
