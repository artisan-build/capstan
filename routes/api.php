<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Http\Controllers\Api\ArtifactController;
use App\Http\Controllers\Api\PollController;
use App\Http\Controllers\Api\WorkDrainController;
use App\Http\Controllers\Api\WorkWorkerController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('work/items/{item}/brief', WorkWorkerController::class)->name('api.work.worker.brief');
    Route::post('work/items/{item}/milestone', WorkWorkerController::class)->name('api.work.worker.milestone');
    Route::get('work/items/{item}/steers', WorkWorkerController::class)->name('api.work.worker.steers');
    Route::post('artifacts', [ArtifactController::class, 'store'])
        ->middleware('capstan.bound_credential:'.CapstanCredentialDeclaration::ARTIFACT_INGEST)
        ->middleware('throttle:api');

    Route::post('poll', PollController::class)
        ->middleware('capstan.bound_credential:'.CapstanCredentialDeclaration::POSTMASTER_POLL)
        ->middleware('throttle:api')
        ->name('api.postmaster.poll');

    Route::prefix('work/{team}')
        ->middleware('capstan.bound_credential:'.CapstanCredentialDeclaration::WORK_DRAIN)
        ->group(function (): void {
            Route::post('claim', [WorkDrainController::class, 'claim'])->name('api.work.claim');
            Route::post('transition', [WorkDrainController::class, 'transition'])->name('api.work.transition');
            Route::post('renew', [WorkDrainController::class, 'renew'])->name('api.work.renew');
        });
});
