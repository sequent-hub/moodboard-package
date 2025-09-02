# MoodBoard API Documentation

## Endpoints

### POST /api/moodboard/save
Сохраняет данные доски настроения.

**Request Format:**
```json
{
    "boardId": "string|number", // ID доски (обязательно)
    "boardData": {              // Данные доски (опционально)
        "objects": [...],       // Массив объектов на доске
        "name": "string",       // Название доски
        "description": "string" // Описание доски
    }
}
```

**Alternative Formats:**
```json
// Формат 1: с cardId
{
    "cardId": "string|number",
    "data": {...}
}

// Формат 2: только ID
{
    "boardId": "default"
}
```

**Response:**
```json
{
    "success": true,
    "message": "Данные успешно сохранены",
    "timestamp": "2024-01-01T00:00:00.000Z",
    "version": 1
}
```

**Error Response (422):**
```json
{
    "success": false,
    "message": "Некорректные данные",
    "errors": {
        "boardId": ["The boardId field is required."]
    },
    "received_data": {...} // Для отладки
}
```

## Common Issues

### 422 Unprocessable Content
- **Причина**: Неправильный формат данных или отсутствие обязательных полей
- **Решение**: Проверить, что `boardId` присутствует в запросе

### CSRF Token Issues
- **Причина**: Отсутствует CSRF токен для веб-запросов
- **Решение**: Добавить CSRF токен в заголовки или использовать API middleware

### Content-Type Issues
- **Причина**: Неправильный Content-Type заголовок
- **Решение**: Установить `Content-Type: application/json`

## Frontend Integration

### JavaScript Example
```javascript
// Правильный запрос
fetch('/api/moodboard/save', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    },
    body: JSON.stringify({
        boardId: 123,
        boardData: {
            objects: [...],
            name: "My Board"
        }
    })
})
.then(response => response.json())
.then(data => console.log(data));
```

### MoodBoard Frontend Integration
```javascript
// Если MoodBoard отправляет данные в другом формате
const saveData = {
    cardId: boardId,  // Вместо boardId
    data: boardData   // Вместо boardData
};
```
