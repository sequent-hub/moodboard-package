<?php

use Futurello\MoodBoard\Http\Controllers\AiController;
use Futurello\MoodBoard\Http\Controllers\FileController;
use Futurello\MoodBoard\Http\Controllers\ImageController;
use Futurello\MoodBoard\Http\Controllers\MoodBoardController;
use Illuminate\Support\Facades\Route;

$v2NotImplemented = static function () {
    return response()->json([
        'success' => false,
        'message' => 'Not implemented in API v2 yet',
    ], 501);
};

Route::prefix('api/v2')->group(function () use ($v2NotImplemented) {
    Route::prefix('moodboard')->group(function () use ($v2NotImplemented) {
        // V2: history-first API
        Route::post('/metadata/save', [MoodBoardController::class, 'moodboardMetaSave']);
        Route::post('/history/save', [MoodBoardController::class, 'historySave']);

        // TODO(v2): reserve legacy-compatible routes until dedicated handlers are implemented.
        Route::get('/list', $v2NotImplemented); // legacy: GET /api/moodboard/list
        Route::post('/save', $v2NotImplemented); // legacy: POST /api/moodboard/save
        Route::get('/load/{boardId}', $v2NotImplemented); // legacy alias: GET /api/moodboard/load/{boardId}
        Route::get('/show/{boardId}', $v2NotImplemented); // legacy: GET /api/moodboard/show/{boardId}
        Route::delete('/delete/{boardId}', $v2NotImplemented); // legacy: DELETE /api/moodboard/delete/{boardId}
        Route::post('/duplicate/{boardId}', $v2NotImplemented); // legacy: POST /api/moodboard/duplicate/{boardId}
        Route::get('/{boardId}/images/stats', $v2NotImplemented); // legacy: GET /api/moodboard/{boardId}/images/stats

        Route::get('/{moodboard_id}/{version?}', [MoodBoardController::class, 'moodboardLoad']);
    });

    Route::prefix('images')->group(function () use ($v2NotImplemented) {
        Route::post('/upload', [ImageController::class, 'upload']);
    });

    Route::prefix('files')->group(function () {
        Route::post('/upload', [FileController::class, 'upload']);
    });

    // AI proxy routes (порт server/src/routes/ai.js).
    // Контракт payload и формат SSE — 1:1 с Node-заглушкой.
    Route::prefix('ai')->group(function () {
        Route::get('/providers', [AiController::class, 'providers']);
        Route::post('/{provider}/image', [AiController::class, 'image']);
        Route::post('/{provider}/chat', [AiController::class, 'chat']);
    });
});
