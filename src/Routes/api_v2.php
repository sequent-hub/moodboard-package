<?php

use Futurello\MoodBoard\Http\Controllers\MoodBoardController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v2')->group(function () {
    Route::prefix('moodboard')->group(function () {
        // V2: history-first API
        Route::get('/{moodboard_id}/{version?}', [MoodBoardController::class, 'moodboardLoad']);
        Route::post('/metadata/save', [MoodBoardController::class, 'moodboardMetaSave']);
        Route::post('/history/save', [MoodBoardController::class, 'historySave']);
    });
});
