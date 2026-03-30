<?php

use Futurello\MoodBoard\Http\Controllers\MoodBoardController;
use Futurello\MoodBoard\Http\Controllers\ImageController;
use Futurello\MoodBoard\Http\Controllers\FileController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v2')->group(function () {
    Route::prefix('moodboard')->group(function () {
        // V2: history-first API
        Route::get('/{moodboard_id}/{version?}', [MoodBoardController::class, 'moodboardLoad']);
        Route::post('/metadata/save', [MoodBoardController::class, 'moodboardMetaSave']);
        Route::post('/history/save', [MoodBoardController::class, 'historySave']);
    });

    Route::prefix('images')->group(function () {
        Route::post('/upload', [ImageController::class, 'upload']);
        Route::get('/{imageId}', [ImageController::class, 'show']);
        Route::get('/{imageId}/download', [ImageController::class, 'file']);
    });

    Route::prefix('files')->group(function () {
        Route::post('/upload', [FileController::class, 'upload']);
        Route::get('/{fileId}', [FileController::class, 'show']);
        Route::get('/{fileId}/download', [FileController::class, 'download']);
    });
});
