<?php

use Comhon\Calendar\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

$attributes = [
    'domain' => config('calendar-core.domain'),
    'prefix' => config('calendar-core.route_prefix'),
    'middleware' => config('calendar-core.middleware'),
];
Route::group($attributes, function () {
    Route::apiResource('events', EventController::class)->except(['destroy']);
    Route::prefix('events/{event}')->group(function () {
        Route::get('participants', [EventController::class, 'getParticipants']);
        Route::post('cancel', [EventController::class, 'cancel']);
        Route::post('accept', [EventController::class, 'accept']);
        Route::post('reschedule', [EventController::class, 'reschedule']);
        Route::post('participants/sync', [EventController::class, 'syncParticipants']);
        Route::post('participants/detach', [EventController::class, 'detachParticipants']);
    });
});
