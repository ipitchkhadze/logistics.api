<?php

declare(strict_types=1);

use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\HoldController;
use Illuminate\Support\Facades\Route;

Route::get('/slots/availability', [AvailabilityController::class, 'index']);
Route::post('/slots/{id}/hold', [HoldController::class, 'store'])->whereNumber('id');
Route::post('/holds/{id}/confirm', [HoldController::class, 'confirm'])->whereNumber('id');
Route::delete('/holds/{id}', [HoldController::class, 'destroy'])->whereNumber('id');
