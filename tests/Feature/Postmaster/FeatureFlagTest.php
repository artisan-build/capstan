<?php

use App\Features\Postmaster;
use Laravel\Pennant\Feature;

test('postmaster feature resolves from config', function (bool $configured): void {
    config(['capstan.features.postmaster' => $configured]);
    Feature::flushCache();

    expect(Feature::active(Postmaster::class))->toBe($configured);
})->with([
    'enabled' => true,
    'disabled' => false,
]);
