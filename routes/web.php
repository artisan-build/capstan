<?php

use App\Http\Controllers\ArtifactShareController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PageExportController;
use App\Http\Controllers\PageShareController;
use Illuminate\Support\Facades\Route;

Route::middleware('bfc.auth')->group(function (): void {
    Route::livewire('postmaster', 'postmaster.spoke-map')->name('postmaster.map');
});

// The content route lives in routes/render.php on a lean, sessionless stack.
Route::get('artifacts/{artifact}/share', [ArtifactShareController::class, 'show'])
    ->middleware('capstan.noindex_artifacts')
    ->name('artifacts.share');

Route::middleware('capstan.noindex_artifacts')->group(function (): void {
    Route::get('pages', [PageController::class, 'index'])->name('pages.index');
    Route::post('pages/{page}/shares', [PageShareController::class, 'store'])->name('pages.shares.store');
    Route::delete('pages/{page}/shares/{share}', [PageShareController::class, 'destroy'])->name('pages.shares.destroy');
    Route::get('pages/{page}/export', PageExportController::class)->name('pages.export');
    Route::delete('pages/{page}', [PageController::class, 'destroy'])->name('pages.destroy');
    Route::get('pages/{page}', [PageController::class, 'show'])->name('pages.show');
    Route::get('pages/{page}/versions/{version}', [PageController::class, 'version'])
        ->name('pages.versions.show');
});
