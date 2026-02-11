<?php


use App\Http\Controllers\GoldController;



 Route::controller(GoldController::class)->group(function () {
     Route::get('/', 'landing')->name('landing');
     Route::get('/api/get-analysis',  'getAnalysis');
 });
