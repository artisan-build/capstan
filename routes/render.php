<?php

use App\Http\Controllers\ArtifactShareController;
use App\Http\Controllers\BrandAssetController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PublicPageShareController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

// Artifact blobs are served on the render origin through a deliberately lean,
// sessionless stack: no StartSession, EncryptCookies, or CSRF, so responses are
// cookieless by construction (D22). Authorization is the signed URL, validated
// in the controller — never a session.
Route::middleware([SubstituteBindings::class, 'capstan.noindex_artifacts'])->group(function (): void {
    Route::get('page-shares/{share}', [PublicPageShareController::class, 'show'])->name('page-shares.show');
    Route::get('page-shares/{share}/content', [PublicPageShareController::class, 'content'])->name('page-shares.content');
    Route::get('artifacts/{artifact}/content', [ArtifactShareController::class, 'content'])->name('artifacts.content');
    Route::get('brand-assets/{asset}', [BrandAssetController::class, 'show'])->name('brand-assets.show');
    Route::get('pages/{page}/versions/{version}/content', [PageController::class, 'content'])
        ->scopeBindings()
        ->name('pages.content');
});
