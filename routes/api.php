<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LineBotController;

Route::post('/parrot', [LineBotController::class, 'parrot']);

// 洗濯ものを取り込むかどうか
Route::post('/bringInTheLaundry', [LineBotController::class, 'bringInTheLaundry']);