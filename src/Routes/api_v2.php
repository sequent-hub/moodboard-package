<?php

use Futurello\MoodBoard\Http\Controllers\MoodBoardController;
use Futurello\MoodBoard\Http\Controllers\ImageController;
use Futurello\MoodBoard\Http\Controllers\FileController;
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

        // TODO(v2): reserve legacy-compatible routes until dedicated handlers are implemented.
        Route::get('/', $v2NotImplemented); // legacy: GET /api/images
        Route::post('/bulk-delete', $v2NotImplemented); // legacy: POST /api/images/bulk-delete
        Route::delete('/{id}', $v2NotImplemented); // legacy: DELETE /api/images/{id}
        Route::get('/{id}/file', $v2NotImplemented); // legacy alias: GET /api/images/{id}/file
    });

    Route::prefix('files')->group(function () {
        Route::post('/upload', [FileController::class, 'upload']);
    });
});
