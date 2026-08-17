<?php

use App\Http\Controllers\ArtifactShareController;
use App\Http\Controllers\Cli\AuthorizeController;
use App\Http\Controllers\Cli\DeviceVerifyController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

Route::view('/', 'welcome')->name('home');

if (Features::enabled(Features::registration())) {
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware(['guest:'.config('fortify.guard'), 'throttle:register'])
        ->name('register.store');
}

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('postmaster', 'postmaster.spoke-map')->name('postmaster.map');
    Route::livewire('team', 'pages::team')->name('team.index');
});

// The content route lives in routes/render.php on a lean, sessionless stack.
Route::get('artifacts/{artifact}/share', [ArtifactShareController::class, 'show'])
    ->middleware('capstan.noindex_artifacts')
    ->name('artifacts.share');

Route::middleware('auth')->group(function (): void {
    Route::get('cli/authorize', [AuthorizeController::class, 'show'])->name('cli.authorize.show');
    Route::post('cli/authorize', [AuthorizeController::class, 'store'])->name('cli.authorize');

    Route::middleware('throttle:cli-verify')->group(function (): void {
        Route::get('cli/device', [DeviceVerifyController::class, 'show'])->name('cli.device.verify');
        Route::post('cli/device', [DeviceVerifyController::class, 'store']);
    });
});

require __DIR__.'/settings.php';
