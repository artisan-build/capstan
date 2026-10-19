<?php

use App\Http\ApiErrorException;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkItem;
use App\Models\WorkTask;
use App\Postmaster\RetireSpoke;
use App\Support\BoundCredentialSpokeResolver;
use App\Work\WorkClaimService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

test('retired credential and synthetic spokes cannot receive Work claims', function (bool $credentialBacked): void {
    $user = capstanUser();
    $actor = (string) $user->id;
    $identity = (string) Str::uuid7();
    $retired = Spoke::query()->create([
        'actor_id' => $actor,
        'credential_id' => $identity,
        'credential_backed' => $credentialBacked,
    ]);
    resolve(RetireSpoke::class)->handle($retired->id, $actor, CarbonImmutable::now());
    $task = WorkTask::query()->create([
        'team_id' => Team::default()->id,
        'title' => 'Retired spoke claim guard',
        'failover' => true,
        'required_capabilities' => [],
        'timebox_minutes' => 30,
        'creator_actor' => $actor,
    ]);
    $item = $task->items()->create(['role' => 'builder']);

    $resolver = resolve(BoundCredentialSpokeResolver::class);
    if ($credentialBacked) {
        expect(fn () => $resolver->resolve($actor, $identity, CarbonImmutable::now(), true))
            ->toThrow(ApiErrorException::class, 'This Postmaster spoke has been retired.');
        $claimant = $retired;
    } else {
        $claimant = $resolver->resolve($actor, $identity, CarbonImmutable::now(), false);
        expect($claimant->id)->not->toBe($retired->id)
            ->and($claimant->retired_at)->toBeNull()
            ->and($claimant->credential_id)->toBe($identity);
    }

    $claimed = resolve(WorkClaimService::class)->claim(
        $task->team_id,
        $actor,
        $claimant->id,
        ['builder'],
        [],
        1,
        CarbonImmutable::now(),
        300,
    );

    if ($credentialBacked) {
        expect($claimed)->toBe([])
            ->and($item->fresh()?->state->value)->toBe('queued');
    } else {
        expect($claimed)->toHaveCount(1)
            ->and($claimed[0]->claimant_spoke_id)->toBe($claimant->id)
            ->and($claimed[0]->claimant_spoke_id)->not->toBe($retired->id);
    }

    expect($retired->refresh()->retired_at)->not->toBeNull()
        ->and(Spoke::query()->find($retired->id))->not->toBeNull()
        ->and(WorkItem::query()->find($item->id))->not->toBeNull();
})->with([
    'credential-backed' => true,
    'synthetic delegated identity' => false,
]);
