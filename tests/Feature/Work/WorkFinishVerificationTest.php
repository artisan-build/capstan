<?php

use App\Enums\WorkItemState;
use App\Http\ApiErrorException;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkFinish;
use App\Models\WorkItem;
use App\Models\WorkOrchestrationEvent;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkSteer;
use App\Models\WorkTask;
use App\Work\AmpSessionReference;
use App\Work\WorkClaimService;
use App\Work\WorkFinishService;
use App\Work\WorkItemStateService;
use App\Work\WorkOrchestrationContext;
use App\Work\WorkOrchestrationSurface;
use App\Work\WorkWorkerLinks;
use App\Work\WorkWorkerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Date::setTestNow('2026-10-25 14:00:00');
    Team::default()->update(['pool_work' => true]);
});

/** @return array{root: WorkTask, child: WorkTask, item: WorkItem, finish: WorkFinish, worker: Spoke, turn: WorkItem, context: WorkOrchestrationContext} */
function finishVerificationFixture(): array
{
    $root = WorkTask::query()->create([
        'team_id' => Team::default()->id,
        'title' => 'Finish root '.Str::random(8),
        'required_capabilities' => [],
        'timebox_minutes' => 10,
        'creator_actor' => 'root-owner',
    ]);
    $child = WorkTask::query()->create([
        'team_id' => $root->team_id,
        'parent_task_id' => $root->id,
        'title' => 'Finish child '.Str::random(8),
        'required_capabilities' => [],
        'timebox_minutes' => 10,
        'creator_actor' => 'root-owner',
    ]);
    $worker = Spoke::query()->create([
        'actor_id' => 'finish-worker',
        'credential_id' => (string) Str::uuid7(),
        'last_polled_at' => now(),
    ]);
    $reference = AmpSessionReference::make('finish-project', 'T-finish-thread', 'https://ampcode.com/threads/T-finish-thread');
    $item = $child->items()->create([
        'role' => 'builder',
        'state' => WorkItemState::Routed,
        'fence' => 3,
        'claimant_actor' => $worker->actor_id,
        'claimant_spoke_id' => $worker->id,
        'claimed_at' => now()->subMinute(),
        'lease_until' => now()->addMinutes(5),
        'work_deadline_at' => now()->addMinutes(10),
        'handed_off_at' => now()->subMinute(),
        'handed_off_session_ref' => $reference->encode(),
        'amp_project_id' => $reference->projectId,
        'amp_thread_id' => $reference->threadId,
        'launch_nonce' => (string) Str::uuid7(),
    ]);
    $finish = resolve(WorkFinishService::class)->declare(
        $item->id,
        $item->fence,
        (string) $item->launch_nonce,
        'Finished test work '.Str::random(10),
        [
            'repo' => 'artisan-build/capstan',
            'number' => 314,
            'url' => 'https://github.com/artisan-build/capstan/pull/314',
        ],
    );
    $orchestrator = Spoke::query()->create([
        'actor_id' => 'finish-orchestrator',
        'credential_id' => (string) Str::uuid7(),
        'last_polled_at' => now(),
    ]);
    $turn = resolve(WorkClaimService::class)->claim(
        $root->team_id,
        $orchestrator->actor_id,
        $orchestrator->id,
        [WorkItem::ROLE_ORCHESTRATOR],
        [],
        1,
        CarbonImmutable::now(),
        300,
    )[0];
    $context = resolve(WorkOrchestrationSurface::class)->claimed($turn->id, $turn->fence);

    return compact('root', 'child', 'item', 'finish', 'worker', 'turn', 'context');
}

test('current turn accept settles once stores the terminal summary and unblocks a dependent', function (): void {
    $fixture = finishVerificationFixture();
    $dependent = WorkTask::query()->create([
        'team_id' => $fixture['root']->team_id,
        'title' => 'Dependent '.Str::random(8),
        'required_capabilities' => [],
        'timebox_minutes' => 10,
        'creator_actor' => 'root-owner',
    ]);
    $dependent->dependencies()->attach($fixture['child']->id);
    $queued = $dependent->items()->create(['role' => 'builder']);
    $dependentHost = Spoke::query()->create([
        'actor_id' => 'dependent-worker', 'credential_id' => (string) Str::uuid7(), 'last_polled_at' => now(),
    ]);
    expect(resolve(WorkClaimService::class)->claim(
        $dependent->team_id, $dependentHost->actor_id, $dependentHost->id, ['builder'], [], 1, now(), 300,
    ))->toBe([]);

    $surface = resolve(WorkOrchestrationSurface::class);
    $input = [
        'finish_id' => $fixture['finish']->id,
        'item_id' => $fixture['item']->id,
        'verdict' => 'accept',
    ];
    $first = $surface->act($fixture['context'], 'review_finish', $input, now());
    $replay = $surface->act($fixture['context'], 'review_finish', $input, now());
    $item = $fixture['item']->fresh();
    $finish = $fixture['finish']->fresh();
    $claimed = resolve(WorkClaimService::class)->claim(
        $dependent->team_id, $dependentHost->actor_id, $dependentHost->id, ['builder'], [], 1, now(), 300,
    );

    expect($first['id'])->toBe($fixture['finish']->id)
        ->and($replay['id'])->toBe($fixture['finish']->id)
        ->and($finish->status)->toBe('accept')
        ->and($finish->verdict_actor)->toBe('finish-orchestrator')
        ->and($finish->verdict_turn_item_id)->toBe($fixture['turn']->id)
        ->and($item->state)->toBe(WorkItemState::Acted)
        ->and($item->last_progress)->toBe($finish->writeup)
        ->and($item->milestones()->where('kind', 'done')->count())->toBe(1)
        ->and($item->transitions()->where('to_state', 'acted')->count())->toBe(1)
        ->and($claimed)->toHaveCount(1)
        ->and($claimed[0]->id)->toBe($queued->id);

    expect(fn () => $surface->act($fixture['context'], 'review_finish', [
        ...$input,
        'verdict' => 'corrections',
        'corrections' => 'A second effect must be refused.',
    ], now()))->toThrow(ApiErrorException::class);
    expect($fixture['item']->milestones()->where('kind', 'done')->count())->toBe(1);
});

test('the signed orchestrator action ingress applies a fenced finish verdict', function (): void {
    $fixture = finishVerificationFixture();
    $url = resolve(WorkWorkerLinks::class)->forItem($fixture['turn'])['orchestrate'];

    $this->postJson($url, [
        'action' => 'review_finish',
        'input' => [
            'finish_id' => $fixture['finish']->id,
            'item_id' => $fixture['item']->id,
            'verdict' => 'accept',
        ],
        'idempotency_key' => 'signed-finish-verdict',
    ])->assertOk()->assertJsonPath('outcome', 'accepted');

    expect($fixture['finish']->fresh()->status)->toBe('accept')
        ->and($fixture['item']->fresh()->state)->toBe(WorkItemState::Acted)
        ->and($fixture['item']->milestones()->where('kind', 'done')->count())->toBe(1);
});

test('corrections preserve the claim and Amp thread while creating one steer and fresh deadline', function (): void {
    $fixture = finishVerificationFixture();
    $itemBefore = $fixture['item']->fresh();
    $corrections = 'Correct the test-created edge case '.Str::random(8);
    $input = [
        'finish_id' => $fixture['finish']->id,
        'item_id' => $fixture['item']->id,
        'verdict' => 'corrections',
        'corrections' => $corrections,
    ];
    $surface = resolve(WorkOrchestrationSurface::class);

    $surface->act($fixture['context'], 'review_finish', $input, now());
    $surface->act($fixture['context'], 'review_finish', $input, now());

    $item = $fixture['item']->fresh();
    $finish = $fixture['finish']->fresh();
    expect($finish->status)->toBe('corrections')
        ->and($finish->correction_text)->toBe($corrections)
        ->and(WorkSteer::query()->where('work_item_id', $item->id)->count())->toBe(1)
        ->and($item->state)->toBe(WorkItemState::Routed)
        ->and($item->fence)->toBe($itemBefore->fence)
        ->and($item->claimant_spoke_id)->toBe($itemBefore->claimant_spoke_id)
        ->and($item->claimant_actor)->toBe($itemBefore->claimant_actor)
        ->and($item->launch_nonce)->toBe($itemBefore->launch_nonce)
        ->and($item->amp_project_id)->toBe($itemBefore->amp_project_id)
        ->and($item->amp_thread_id)->toBe($itemBefore->amp_thread_id)
        ->and($item->handed_off_session_ref)->toBe($itemBefore->handed_off_session_ref)
        ->and($item->work_deadline_at?->equalTo(now()->addMinutes(10)))->toBeTrue()
        ->and($item->first_deadline_missed_at)->toBeNull()
        ->and($item->stalled_at)->toBeNull();

    $secondWriteup = 'Corrected finish writeup '.Str::random(8);
    $second = resolve(WorkFinishService::class)->declare(
        $item->id,
        $item->fence,
        (string) $item->launch_nonce,
        $secondWriteup,
        $finish->pr,
    );
    $refinished = $item->fresh();
    $newEvent = WorkOrchestrationEvent::query()->whereKey($second->orchestration_event_id)->sole();

    expect($second->id)->not->toBe($finish->id)
        ->and($second->status)->toBe('awaiting')
        ->and($second->writeup)->toBe($secondWriteup)
        ->and(WorkFinish::query()->where('work_item_id', $item->id)->count())->toBe(2)
        ->and(WorkOrchestrationEvent::query()->where('kind', 'child_awaiting_verification')->count())->toBe(2)
        ->and($newEvent->id)->not->toBe($finish->orchestration_event_id)
        ->and($newEvent->evidence['finish_id'])->toBe($second->id)
        ->and($refinished->state)->toBe(WorkItemState::AwaitingVerification)
        ->and($refinished->fence)->toBe($itemBefore->fence)
        ->and($refinished->claimant_spoke_id)->toBe($itemBefore->claimant_spoke_id)
        ->and($refinished->claimant_actor)->toBe($itemBefore->claimant_actor)
        ->and($refinished->launch_nonce)->toBe($itemBefore->launch_nonce)
        ->and($refinished->amp_project_id)->toBe($itemBefore->amp_project_id)
        ->and($refinished->amp_thread_id)->toBe($itemBefore->amp_thread_id)
        ->and($refinished->handed_off_session_ref)->toBe($itemBefore->handed_off_session_ref)
        ->and($refinished->work_deadline_at)->toBeNull()
        ->and(WorkSteer::query()->where('work_item_id', $item->id)->count())->toBe(1);
});

test('review finish refuses stale turn and seat fences wrong targets and invalid verdict payloads without effects', function (): void {
    $fixture = finishVerificationFixture();
    $surface = resolve(WorkOrchestrationSurface::class);
    $valid = [
        'finish_id' => $fixture['finish']->id,
        'item_id' => $fixture['item']->id,
        'verdict' => 'accept',
    ];
    $snapshot = fn (): array => [
        $fixture['finish']->fresh()->status,
        $fixture['item']->fresh()->state,
        $fixture['item']->milestones()->count(),
        WorkSteer::query()->count(),
    ];
    $before = $snapshot();

    expect(fn () => $surface->act(
        $surface->claimed($fixture['turn']->id, $fixture['turn']->fence + 1),
        'review_finish',
        $valid,
        now(),
    ))->toThrow(ApiErrorException::class);
    expect(fn () => $surface->act($fixture['context'], 'review_finish', [
        ...$valid,
        'item_id' => (string) Str::uuid7(),
    ], now()))->toThrow(ApiErrorException::class);
    foreach ([
        [...$valid, 'verdict' => 'corrections'],
        [...$valid, 'verdict' => 'corrections', 'corrections' => '   '],
        [...$valid, 'corrections' => 'Not valid for accept'],
        [...$valid, 'machine_state' => 'open'],
    ] as $invalid) {
        expect(fn () => $surface->act($fixture['context'], 'review_finish', $invalid, now()))
            ->toThrow(ApiErrorException::class);
    }
    expect($snapshot())->toBe($before);

    $seat = WorkOrchestratorSeat::query()->where('work_task_id', $fixture['root']->id)->sole();
    $seat->increment('fence');
    expect(fn () => $surface->act($fixture['context'], 'review_finish', $valid, now()))
        ->toThrow(ApiErrorException::class);
    expect($snapshot())->toBe($before);
});

test('a current seat cannot review a finish from another root subtree', function (): void {
    $first = finishVerificationFixture();
    $second = finishVerificationFixture();
    $before = [
        $second['finish']->fresh()->status,
        $second['item']->fresh()->state,
        $second['item']->milestones()->count(),
    ];

    expect(fn () => resolve(WorkOrchestrationSurface::class)->act($first['context'], 'review_finish', [
        'finish_id' => $second['finish']->id,
        'item_id' => $second['item']->id,
        'verdict' => 'accept',
    ], now()))->toThrow(ApiErrorException::class);
    expect([
        $second['finish']->fresh()->status,
        $second['item']->fresh()->state,
        $second['item']->milestones()->count(),
    ])->toBe($before);
});

test('awaiting verification can renew but rejects progress start and machine completion paths', function (): void {
    $fixture = finishVerificationFixture();
    $item = $fixture['item']->fresh();
    $states = resolve(WorkItemStateService::class);
    $renewed = $states->renewClaim(
        $item,
        $fixture['root']->team_id,
        (int) $item->claimant_spoke_id,
        (string) $item->claimant_actor,
        $item->fence,
        now(),
        now()->addMinutes(8),
    );

    expect($renewed)->not->toBeNull()
        ->and($renewed?->state)->toBe(WorkItemState::AwaitingVerification)
        ->and($renewed?->lease_until?->equalTo(now()->addMinutes(8)))->toBeTrue();
    expect(fn () => $states->transitionClaim(
        $item, WorkItemState::Failed, $fixture['root']->team_id, (int) $item->claimant_spoke_id,
        (string) $item->claimant_actor, $item->fence, now(),
    ))->toThrow(DomainException::class);
    expect(fn () => $states->releaseClaim(
        $item, $fixture['root']->team_id, (int) $item->claimant_spoke_id,
        (string) $item->claimant_actor, $item->fence, now(),
    ))->toThrow(DomainException::class);
    expect(fn () => resolve(WorkWorkerService::class)->started(
        $item->id, $item->fence, (string) $item->launch_nonce,
    ))->toThrow(HttpException::class);
    expect(fn () => resolve(WorkWorkerService::class)->progress(
        $item->id, $item->fence, (string) $item->launch_nonce, 'Must not write progress',
    ))->toThrow(HttpException::class);

    $replay = resolve(WorkFinishService::class)->declare(
        $item->id,
        $item->fence,
        (string) $item->launch_nonce,
        $fixture['finish']->writeup,
        $fixture['finish']->pr,
    );
    expect($replay->id)->toBe($fixture['finish']->id)
        ->and($fixture['item']->fresh()->state)->toBe(WorkItemState::AwaitingVerification);
});

test('lost cancelled and conflicting finish declarations fail closed', function (string $change): void {
    $fixture = finishVerificationFixture();
    $item = $fixture['item'];
    $fence = $item->fence;
    $nonce = (string) $item->launch_nonce;
    $item->forceFill(['state' => WorkItemState::Routed])->save();
    $fixture['finish']->forceFill(['status' => 'corrections'])->save();
    match ($change) {
        'fence' => $item->increment('fence'),
        'nonce' => $item->forceFill(['launch_nonce' => (string) Str::uuid7()])->save(),
        'cancelled claim' => $item->forceFill(['state' => WorkItemState::Cancelled])->save(),
        'cancelled task' => $fixture['child']->forceFill(['cancelled_at' => now()])->save(),
    };
    $count = WorkFinish::query()->count();

    expect(fn () => resolve(WorkFinishService::class)->declare(
        $item->id,
        $fence,
        $nonce,
        'Conflicting finish',
        $fixture['finish']->pr,
    ))->toThrow(HttpException::class);
    expect(WorkFinish::query()->count())->toBe($count)
        ->and(WorkOrchestrationEvent::query()->where('kind', 'child_awaiting_verification')->count())->toBe(1);
})->with(['fence', 'nonce', 'cancelled claim', 'cancelled task']);
