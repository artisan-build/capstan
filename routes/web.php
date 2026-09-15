<?php

use App\Http\Controllers\ArtifactShareController;
use Illuminate\Support\Facades\Route;

Route::middleware('bfc.auth')->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('postmaster', 'postmaster.spoke-map')->name('postmaster.map');
});

// The content route lives in routes/render.php on a lean, sessionless stack.
Route::get('artifacts/{artifact}/share', [ArtifactShareController::class, 'show'])
    ->middleware('capstan.noindex_artifacts')
    ->name('artifacts.share');
