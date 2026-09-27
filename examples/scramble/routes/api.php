<?php

declare(strict_types=1);

use App\Http\Controllers\ContractProbeController;
use Illuminate\Support\Facades\Route;

Route::post('/users', [ContractProbeController::class, 'store']);
Route::get('/users/{user}', [ContractProbeController::class, 'show']);
Route::get('/profile', [ContractProbeController::class, 'profile'])->middleware('auth');
