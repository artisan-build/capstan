<?php

use App\Features\Postmaster;
use Laravel\Pennant\Feature;

test('postmaster remains available when obsolete config is false', function (): void {
    config(['capstan.features.postmaster' => false]);
    Feature::flushCache();

    expect(Feature::active(Postmaster::class))->toBeTrue();
});
