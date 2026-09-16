<?php

use App\Models\Team;

test('fresh seeding has exactly one default artifact grant team', function (): void {
    $this->seed();
    $this->seed();

    expect(Team::query()->where('is_default', true)->count())->toBe(1)
        ->and(Team::query()->where('slug', Team::DEFAULT_SLUG)->count())->toBe(1);
});
