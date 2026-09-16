<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Http\Controllers\Api\ArtifactController;
use App\Http\Controllers\Api\PollController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('artifacts', [ArtifactController::class, 'store'])
        ->middleware('capstan.bound_credential:'.CapstanCredentialDeclaration::ARTIFACT_INGEST)
        ->middleware('throttle:api');

    Route::post('poll', PollController::class)
        ->middleware('capstan.bound_credential:'.CapstanCredentialDeclaration::POSTMASTER_POLL)
        ->middleware('throttle:api')
        ->name('api.postmaster.poll');
});
