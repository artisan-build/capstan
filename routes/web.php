<?php

use App\Http\Controllers\ArtifactShareController;
use App\Http\Controllers\BrandingSettingsController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PageExportController;
use App\Http\Controllers\PageShareController;
use App\Livewire\Work\Index;
use Illuminate\Support\Facades\Route;

Route::middleware('bfc.auth')->group(function (): void {
    Route::livewire('work', Index::class)->name('work.index');
    Route::livewire('postmaster', 'postmaster.spoke-map')->name('postmaster.map');
    Route::get('branding', [BrandingSettingsController::class, 'show'])->name('branding.show');
    Route::patch('branding/draft', [BrandingSettingsController::class, 'update'])->name('branding.draft.update');
    Route::post('branding/assets', [BrandingSettingsController::class, 'upload'])->name('branding.assets.store');
    Route::post('branding/apply', [BrandingSettingsController::class, 'apply'])->name('branding.apply');
    Route::post('branding/versions/{version}/revert', [BrandingSettingsController::class, 'revert'])
        ->whereNumber('version')
        ->name('branding.versions.revert');
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
