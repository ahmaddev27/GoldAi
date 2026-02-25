<?php


use App\Http\Controllers\GoldController;
use App\Http\Controllers\TradeHistoryController;



Route::controller(GoldController::class)->group(function () {
    Route::get('/', 'landing')->name('landing');
    Route::get('/api/get-analysis', 'getAnalysis');
});

// مسارات إدارة التاريخ والإحصائيات
Route::controller(TradeHistoryController::class)->prefix('api/trades')->group(function () {
    Route::post('/save-analysis', 'saveAnalysis');
    Route::post('/close/{analysisId}', 'closeTrade');
    Route::get('/statistics', 'getStatistics');
    Route::get('/recent/{limit?}', 'getRecentAnalyses');
    Route::get('/performance-by-direction', 'performanceByDirection');
});
