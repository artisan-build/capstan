<?php

use App\Http\Controllers\Api\ArtifactController;
use App\Http\Controllers\Api\PollController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function (): void {
    Route::post('artifacts', [ArtifactController::class, 'store']);

    Route::post('poll', PollController::class)
        ->name('api.postmaster.poll');
});
