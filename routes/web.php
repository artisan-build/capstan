<?php

use App\Http\Controllers\ArtifactShareController;
use App\Http\Controllers\Cli\AuthorizeController;
use App\Http\Controllers\Cli\DeviceVerifyController;
use App\Http\Controllers\CliDownloadController;
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

// PUBLIC, unauthenticated serving of capstan-cli release artifacts from the
// private `downloads` bucket (`cli/<version>/<file>`). capstan-cli is a PRIVATE
// repo — there are no public GitHub Release URLs — so this app is the download
// host as well as the gatekeeper. Deliberately outside every auth/team group.
//
// The `{path}` constraint permits version/file paths (dots, hyphens, slashes)
// but EXCLUDES the more-specific `cli/authorize` and `cli/device` auth routes
// above, which keep their own handlers. It also forbids a leading slash,
// backslashes and null bytes; the controller re-validates the same shape.
Route::get('cli/{path}', CliDownloadController::class)
    ->where('path', '(?!authorize$)(?!authorize/)(?!device$)(?!device/)[A-Za-z0-9][A-Za-z0-9._/\-]*')
    ->name('cli.download');

require __DIR__.'/settings.php';
