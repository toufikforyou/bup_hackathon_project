<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::post('/console/optimize', [DashboardController::class, 'optimize'])->name('dashboard.optimize');
Route::get('/console/optimize', fn () => redirect()->route('dashboard'));
