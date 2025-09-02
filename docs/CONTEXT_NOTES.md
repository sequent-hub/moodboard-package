# Context Notes for Cursor IDE

## Project Structure
- Main Laravel app: `/c/Users/popov/Herd/miro`
- Package location: `/c/Users/popov/Herd/miro/moodboard`
- Package namespace: `Futurello\MoodBoard`

## Key Files
- Controller: `moodboard/src/Http/Controllers/MoodBoardController.php`
- Routes: `moodboard/src/Routes/api.php`
- Models: `moodboard/src/Models/MoodBoard.php`

## Common Issues
1. **422 Errors**: Usually validation failures
2. **Route Conflicts**: Main project routes overriding package routes
3. **Data Format**: MoodBoard sends different field names

## Debug Commands
```bash
# Check routes
php artisan route:list --path=api/moodboard

# Check logs
tail -f storage/logs/laravel.log

# Clear caches
php artisan route:clear && php artisan config:clear
```

## Package Updates
- Current version: 1.0.2
- GitHub: https://github.com/futurello/moodboard
- Update: `composer update futurello/moodboard`
