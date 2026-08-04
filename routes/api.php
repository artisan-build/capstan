<?php

use App\Http\Controllers\Api\ArtifactController;
use App\Http\Controllers\Api\DeviceCodeController;
use App\Http\Controllers\Api\MeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function (): void {
    Route::post('cli/device', [DeviceCodeController::class, 'create'])
        ->middleware('throttle:cli-device');

    Route::post('cli/device/token', [DeviceCodeController::class, 'token']);

    Route::get('me', MeController::class)->middleware('capstan.auth');

    Route::post('artifacts', [ArtifactController::class, 'store'])->middleware('capstan.auth');
});
