<?php

use App\Http\Controllers\ArtifactShareController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

// Artifact blobs are served on the render origin through a deliberately lean,
// sessionless stack: no StartSession, EncryptCookies, or CSRF, so responses are
// cookieless by construction (D22). Authorization is the signed URL, validated
// in the controller — never a session.
Route::middleware([SubstituteBindings::class, 'capstan.noindex_artifacts'])->group(function (): void {
    Route::get('artifacts/{artifact}/content', [ArtifactShareController::class, 'content'])->name('artifacts.content');
});
