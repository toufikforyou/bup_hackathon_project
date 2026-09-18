<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\OptimizeEnergyController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::post('/optimize-energy', OptimizeEnergyController::class);
