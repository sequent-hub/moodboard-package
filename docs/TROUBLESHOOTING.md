# Troubleshooting Guide

## Error 422: Unprocessable Content

### Symptoms
- POST request to `/api/moodboard/save` returns 422
- Console shows: "HTTP 422: Unprocessable Content"
- No data saved to database

### Debugging Steps

1. **Check Request Format**
   ```bash
   # Enable detailed logging
   tail -f storage/logs/laravel.log
   ```

2. **Verify Required Fields**
   - `boardId` must be present (string or number)
   - `boardData` is optional but should be array if provided

3. **Check Headers**
   ```javascript
   // Ensure proper headers
   headers: {
       'Content-Type': 'application/json',
       'Accept': 'application/json'
   }
   ```

4. **Validate Data Structure**
   ```json
   // Correct format
   {
       "boardId": "123",
       "boardData": {
           "objects": [],
           "name": "Test Board"
       }
   }
   ```

### Common Solutions

#### Solution 1: Fix Data Format
```javascript
// If MoodBoard sends different field names
const requestData = {
    boardId: cardId || boardId,  // Support both
    boardData: data || boardData // Support both
};
```

#### Solution 2: Add CSRF Protection
```javascript
// For web requests (not API)
headers: {
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
}
```

#### Solution 3: Use API Routes
```php
// In routes/api.php - ensure API middleware
Route::middleware('api')->group(function () {
    // API routes here
});
```

## Log Analysis

### Check Laravel Logs
```bash
# Look for validation errors
grep "validation failed" storage/logs/laravel.log

# Look for MoodBoard save attempts
grep "MoodBoard save" storage/logs/laravel.log
```

### Log Examples
```
[2024-01-01 12:00:00] local.INFO: MoodBoard save request received {"headers": {...}, "all_data": {...}}
[2024-01-01 12:00:01] local.ERROR: MoodBoard validation failed {"errors": {...}}
```

## Testing Endpoints

### Using cURL
```bash
# Test basic save
curl -X POST http://your-app.test/api/moodboard/save \
  -H "Content-Type: application/json" \
  -d '{"boardId": "test123"}'

# Test with data
curl -X POST http://your-app.test/api/moodboard/save \
  -H "Content-Type: application/json" \
  -d '{"boardId": "test123", "boardData": {"objects": []}}'
```

### Using Postman/Insomnia
1. Set method to POST
2. URL: `http://your-app.test/api/moodboard/save`
3. Headers: `Content-Type: application/json`
4. Body (raw JSON):
   ```json
   {
       "boardId": "test123",
       "boardData": {
           "objects": [],
           "name": "Test Board"
       }
   }
   ```

## Package Updates

### Update Package
```bash
composer update futurello/moodboard
```

### Check Package Version
```bash
composer show futurello/moodboard
```

### Clear Caches
```bash
php artisan route:clear
php artisan config:clear
php artisan cache:clear
```
