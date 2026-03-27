<?php

use Illuminate\Support\Facades\Route;
use Futurello\MoodBoard\Http\Controllers\MoodBoardController;
use Futurello\MoodBoard\Http\Controllers\ImageController;
use Futurello\MoodBoard\Http\Controllers\FileController;

// Оборачиваем все маршруты в группу api
Route::prefix('api')->group(function () {

// API маршруты для moodboard
Route::prefix('moodboard')->group(function () {
    // OPTIONS поддержка для CORS
    Route::options('/{any}', [MoodBoardController::class, 'options'])->where('any', '.*');
    
    Route::get('/list', [MoodBoardController::class, 'index']);
    Route::post('/save', [MoodBoardController::class, 'save']);
    Route::post('/history/save', [MoodBoardController::class, 'historySave']);
    Route::get('/load/{boardId}', [MoodBoardController::class, 'load']);
    Route::get('/{boardId}', [MoodBoardController::class, 'load']); // Для совместимости с frontend
    Route::get('/show/{boardId}', [MoodBoardController::class, 'show']);
    Route::delete('/delete/{boardId}', [MoodBoardController::class, 'destroy']);
    Route::post('/duplicate/{boardId}', [MoodBoardController::class, 'duplicate']);

    // Статистика изображений для доски
    Route::get('/{boardId}/images/stats', [MoodBoardController::class, 'getImageStats']);
});

// Изображения
Route::prefix('images')->group(function () {
    // OPTIONS поддержка для CORS
    Route::options('/{any}', [ImageController::class, 'options'])->where('any', '.*');
    
    Route::post('/upload', [ImageController::class, 'upload']);
    Route::get('/{id}', [ImageController::class, 'show']);
    Route::get('/{id}/file', [ImageController::class, 'file'])->name('images.file');
    Route::delete('/{id}', [ImageController::class, 'destroy']);

    Route::get('/', [ImageController::class, 'index']); // Список всех изображений
    Route::post('/bulk-delete', [ImageController::class, 'bulkDelete']); // Массовое удаление
});

// Группа роутов для работы с файлами
Route::prefix('files')->group(function () {
    Route::post('/upload', [FileController::class, 'upload']);
    Route::get('/{id}', [FileController::class, 'show']);
    Route::put('/{id}', [FileController::class, 'update']);
    Route::get('/{id}/download', [FileController::class, 'download']);
    Route::delete('/{id}', [FileController::class, 'destroy']);
});
}); // Закрываем группу api
