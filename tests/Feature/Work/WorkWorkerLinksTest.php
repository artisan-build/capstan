<?php

use App\Enums\WorkItemState;
use App\Enums\WorkMilestoneKind;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkDirective;
use App\Models\WorkItem;
use App\Models\WorkTask;
use App\Postmaster\RetireSpoke;
use App\Work\WorkRepresentation;
use App\Work\WorkWorkerLinks;
use App\Work\WorkWorkerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    config(['capstan.features.work' => true, 'capstan.work.link_grace_seconds' => 60, 'capstan.work.steer_recent_seconds' => 86400]);
    Feature::flushCache();
    Date::setTestNow('2026-10-17 10:00:00');
});

/** @param array<string, mixed> $attributes */
function workerLinkItem(array $attributes = []): WorkItem
{
    $team = Team::query()->create(['name' => 'Worker test', 'slug' => (string) Str::uuid7()]);
    $task = WorkTask::query()->create([
        'team_id' => $team->id, 'title' => 'Worker task '.Str::random(6),
        'required_capabilities' => [], 'timebox_minutes' => 10, 'creator_actor' => 'creator',
    ]);
    $spoke = Spoke::query()->create(['actor_id' => 'worker', 'credential_id' => (string) Str::uuid7()]);

    return $task->items()->create(array_replace([
        'role' => 'builder', 'state' => 'routed', 'fence' => 1,
        'claimant_actor' => 'worker', 'claimant_spoke_id' => $spoke->id,
        'claimed_at' => now(), 'lease_until' => now()->addMinutes(5),
    ], $attributes));
}

test('worker links expire at the observed lease plus grace', function (): void {
    $item = workerLinkItem();
    foreach (resolve(WorkWorkerLinks::class)->forItem($item) as $url) {
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        expect((int) $query['expires'])->toBe(now()->addMinutes(6)->timestamp)
            ->and((int) $query['fence'])->toBe(1);
    }
    $item->update(['lease_until' => now()->addHour()]);
    $firstLinks = resolve(WorkWorkerLinks::class)->forItem($item->refresh());
    expect(resolve(WorkWorkerLinks::class)->forItem($item))->toBe($firstLinks);
    foreach (resolve(WorkWorkerLinks::class)->forItem($item->refresh()) as $url) {
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        expect((int) $query['expires'])->toBe(now()->addHour()->addMinute()->timestamp);
    }
});

test('brief returns latest UUID revision and records it in JSON and markdown during grace', function (): void {
    $item = workerLinkItem();
    $item->workTask->briefRevisions()->create(['sequence' => 1, 'content' => 'Old brief', 'author_actor' => 'creator']);
    $url = resolve(WorkWorkerLinks::class)->forItem($item)['brief'];
    $revision = $item->workTask->briefRevisions()->create(['sequence' => 2, 'content' => 'New '.Str::random(), 'author_actor' => 'creator']);
    Date::setTestNow(now()->addMinutes(5)->addSeconds(30));
    $this->getJson($url)->assertOk()->assertJsonPath('revision.id', $revision->id)
        ->assertJsonPath('revision.content', $revision->content)->assertJsonPath('item.role', 'builder');
    expect($item->fresh()->fetched_brief_revision_id)->toBe($revision->id);
    $this->get($url, ['Accept' => 'text/markdown'])->assertOk()->assertSeeText($revision->content)
        ->assertSeeText($item->workTask->title)->assertSeeText('Role: builder');
});

test('signature binds id fence expiry and endpoint', function (string $change): void {
    $item = workerLinkItem();
    $other = workerLinkItem();
    $url = resolve(WorkWorkerLinks::class)->forItem($item)['brief'];
    $url = match ($change) {
        'id' => str_replace($item->id, $other->id, $url),
        'fence' => str_replace('fence=1', 'fence=2', $url),
        'expiry' => preg_replace('/expires=\d+/', 'expires=9999999999', $url),
        'endpoint' => str_replace('/brief', '/steers', $url),
    };
    $this->getJson($url)->assertForbidden();
    $this->getJson('/api/v1/work/items')->assertNotFound();
})->with(['id', 'fence', 'expiry', 'endpoint']);

test('signatures are refused after the observed lease plus grace', function (): void {
    $url = resolve(WorkWorkerLinks::class)->forItem(workerLinkItem())['brief'];
    Date::setTestNow(now()->addMinutes(7));
    $this->getJson($url)->assertForbidden();
});

test('worker claim rejection applies to all endpoints', function (string $change, int $status): void {
    $item = workerLinkItem();
    $links = resolve(WorkWorkerLinks::class)->forItem($item);
    match ($change) {
        'fence' => $item->update(['fence' => 2]),
        'state' => $item->update(['state' => 'acted']),
        'owner' => $item->update(['claimant_actor' => null]),
        'item-cancelled' => $item->update(['state' => 'cancelled']),
        'task-cancelled' => $item->workTask->update(['cancelled_at' => now()]),
    };
    foreach (['brief', 'steers', 'milestone'] as $action) {
        $response = $action === 'milestone'
            ? $this->postJson($links[$action], ['kind' => 'note', 'payload' => (object) []])
            : $this->getJson($links[$action]);
        $response->assertStatus($status);
        if ($status === 409) {
            $response->assertSeeText('claim lost')->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        }
    }
})->with([['fence', 409], ['state', 409], ['owner', 409], ['item-cancelled', 410], ['task-cancelled', 410]]);

test('retired spoke refuses every signed worker route during link grace without side effects', function (): void {
    $item = workerLinkItem();
    $item->claimantSpoke()->update(['credential_backed' => false]);
    $revision = $item->workTask->briefRevisions()->create([
        'sequence' => 1,
        'content' => 'Must stay unread',
        'author_actor' => 'creator',
    ]);
    $steer = $item->workTask->steers()->create([
        'work_item_id' => $item->id,
        'text' => 'Must stay unread',
        'author_actor' => 'creator',
    ]);
    $links = resolve(WorkWorkerLinks::class)->forItem($item);
    $leaseUntil = $item->lease_until;

    Date::setTestNow(now()->addMinutes(5)->addSeconds(30));
    resolve(RetireSpoke::class)->handle(
        $item->claimant_spoke_id,
        $item->claimant_actor,
        CarbonImmutable::instance(now()),
    );

    $this->getJson($links['brief'])->assertConflict()->assertSeeText('claim lost');
    $this->getJson($links['steers'])->assertConflict()->assertSeeText('claim lost');
    foreach (WorkMilestoneKind::cases() as $kind) {
        $payload = ['summary' => 'Must not persist'];
        if ($kind === WorkMilestoneKind::PullRequestOpened) {
            $payload['pr'] = ['repo' => 'artisan-build/capstan', 'number' => 17, 'url' => 'https://example.test/pull/17'];
        }
        $this->postJson($links['milestone'], [
            'kind' => $kind->value,
            'payload' => $payload,
        ])->assertConflict()->assertSeeText('claim lost');
    }

    $unchanged = $item->refresh();
    expect($unchanged->state)->toBe(WorkItemState::Routed)
        ->and($unchanged->fetched_brief_revision_id)->toBeNull()
        ->and($unchanged->last_progress)->toBeNull()
        ->and($unchanged->attempts)->toBe(0)
        ->and($unchanged->claimant_spoke_id)->not->toBeNull()
        ->and($unchanged->lease_until?->equalTo($leaseUntil))->toBeTrue()
        ->and($unchanged->milestones()->count())->toBe(0)
        ->and($unchanged->transitions()->count())->toBe(0)
        ->and($revision->fresh()->id)->toBe($revision->id)
        ->and($steer->fresh()->read_at)->toBeNull();
});

test('milestone rejects invalid strict objects without writes', function (array $payload): void {
    $item = workerLinkItem();
    $this->postJson(resolve(WorkWorkerLinks::class)->forItem($item)['milestone'], $payload)->assertUnprocessable();
    expect($item->milestones()->count())->toBe(0)->and($item->fresh()->last_progress)->toBeNull();
})->with([
    [['kind' => 'note', 'payload' => (object) [], 'extra' => true]],
    [['kind' => 'note', 'payload' => ['extra' => true]]],
    [['kind' => 'note', 'payload' => ['summary' => str_repeat('x', 2001)]]],
    [['kind' => 'note', 'payload' => ['summary' => str_repeat('😀', 1500)]]],
    [['kind' => 'note', 'payload' => ['url' => 'not-a-url']]],
    [['kind' => 'note', 'payload' => ['url' => 'https://example.test/'.str_repeat('x', 2048)]]],
    [['kind' => 'bogus', 'payload' => (object) []]],
    [['kind' => 'note', 'payload' => []]],
    [['kind' => 'note', 'payload' => ['summary' => ['instruction']]]],
    [['kind' => 'pr_opened', 'payload' => ['pr' => ['repo' => 'artisan-build/capstan', 'number' => 1, 'url' => 'https://example.test/pull/1', 'branch' => 'main']]]],
    [['kind' => 'pr_opened', 'payload' => ['pr' => ['repo' => 'artisan-build/capstan', 'number' => 0, 'url' => 'https://example.test/pull/1']]]],
    [['kind' => 'pr_opened', 'payload' => ['pr' => ['repo' => 'artisan-build/capstan', 'number' => 1, 'url' => 'http://example.test/pull/1']]]],
    [['kind' => 'pr_opened', 'payload' => (object) []]],
]);

test('each milestone appends UUIDv7 and applies legal transitions and progress', function (string $kind, string $state): void {
    $item = workerLinkItem();
    $summary = '<script>untrusted</script> '.Str::random();
    $payload = ['summary' => $summary, 'url' => 'https://example.test/pr/1'];
    if ($kind === 'pr_opened') {
        $payload['pr'] = ['repo' => 'artisan-build/capstan', 'number' => 1, 'url' => 'https://example.test/pr/1'];
    }
    $this->postJson(resolve(WorkWorkerLinks::class)->forItem($item)['milestone'], [
        'kind' => $kind, 'payload' => $payload,
    ])->assertCreated();
    $milestone = $item->milestones()->sole();
    expect($milestone->kind->value)->toBe($kind)->and($milestone->id[14])->toBe('7')
        ->and($milestone->payload['summary'])->toBe($summary)
        ->and($item->fresh()->last_progress)->toBe($summary)->and($item->fresh()->state->value)->toBe($state)
        ->and($item->transitions()->count())->toBe(in_array($kind, ['done', 'failed', 'released']) ? 1 : 0);
    if ($kind === 'released') {
        expect($item->fresh()->attempts)->toBe(1)->and($item->fresh()->claimed_at)->toBeNull()
            ->and($item->fresh()->claimant_spoke_id)->toBeNull()->and($item->fresh()->lease_until)->toBeNull();
    }
})->with([['started', 'routed'], ['blocked', 'routed'], ['pr_opened', 'routed'], ['note', 'routed'], ['done', 'acted'], ['failed', 'failed'], ['released', 'queued']]);

test('signed started opens one deadline idempotently without clearing a pre-existing stall', function (): void {
    $item = workerLinkItem([
        'work_deadline_at' => null,
        'first_deadline_missed_at' => now()->subMinute(),
        'stalled_at' => now()->subMinute(),
    ]);
    $url = resolve(WorkWorkerLinks::class)->forItem($item)['milestone'];

    $first = $this->postJson($url, ['kind' => 'started', 'payload' => (object) []])->assertCreated();
    $deadline = now()->addMinutes(10);
    Date::setTestNow(now()->addMinute());
    $this->postJson($url, ['kind' => 'started', 'payload' => (object) []])
        ->assertCreated()
        ->assertJsonPath('id', $first->json('id'));

    expect($item->milestones()->where('kind', WorkMilestoneKind::Started)->count())->toBe(1)
        ->and($item->fresh()->work_deadline_at?->equalTo($deadline))->toBeTrue()
        ->and($item->fresh()->first_deadline_missed_at)->toBeNull()
        ->and($item->fresh()->stalled_at)->not->toBeNull();
});

test('signed real progress resets the deadline and settles the current repair episode', function (): void {
    $item = workerLinkItem([
        'work_deadline_at' => now()->subMinute(),
        'first_deadline_missed_at' => now()->subMinute(),
        'stalled_at' => now()->subMinute(),
    ]);
    $lease = $item->lease_until;
    foreach (['deadline', 'stall'] as $sequence => $reason) {
        WorkDirective::query()->create([
            'work_item_id' => $item->id,
            'claimant_spoke_id' => $item->claimant_spoke_id,
            'fence' => $item->fence,
            'sequence' => $sequence + 1,
            'kind' => 'nudge',
            'reason' => $reason,
            'payload' => [],
            'status' => 'pending',
            'issued_at' => now()->subMinute(),
        ]);
    }

    $this->postJson(resolve(WorkWorkerLinks::class)->forItem($item)['milestone'], [
        'kind' => 'note',
        'payload' => ['summary' => 'Signed progress moved the deadline'],
    ])->assertCreated();

    expect($item->fresh()->work_deadline_at?->equalTo(now()->addMinutes(10)))->toBeTrue()
        ->and($item->fresh()->first_deadline_missed_at)->toBeNull()
        ->and($item->fresh()->stalled_at)->toBeNull()
        ->and($item->fresh()->lease_until?->equalTo($lease))->toBeTrue()
        ->and($item->directives()->where('status', 'pending')->count())->toBe(0)
        ->and($item->directives()->pluck('outcome')->all())->toBe([
            ['result' => 'resolved_by_progress'],
            ['result' => 'resolved_by_progress'],
        ]);
});

test('signed terminal and released milestones do not open a new deadline', function (string $kind): void {
    $item = workerLinkItem(['work_deadline_at' => null]);

    $this->postJson(resolve(WorkWorkerLinks::class)->forItem($item)['milestone'], [
        'kind' => $kind,
        'payload' => (object) [],
    ])->assertCreated();

    expect($item->fresh()->work_deadline_at)->toBeNull();
})->with(['done', 'failed', 'released']);

test('signed pr_opened sets PR metadata once and safely replays only the same value', function (): void {
    $item = workerLinkItem();
    $url = resolve(WorkWorkerLinks::class)->forItem($item)['milestone'];
    $pr = ['repo' => 'artisan-build/capstan', 'number' => 112, 'url' => 'https://example.test/pull/112'];
    $payload = ['kind' => 'pr_opened', 'payload' => ['pr' => $pr, 'summary' => 'Opened test PR']];

    $first = $this->postJson($url, $payload)->assertCreated()->json('id');
    expect($item->workTask->fresh()->pr)->toBe($pr)
        ->and(resolve(WorkRepresentation::class)->task($item->workTask->fresh())['pr'])->toBe($pr)
        ->and($item->milestones()->count())->toBe(1);
    $this->postJson($url, $payload)->assertCreated()->assertJsonPath('id', $first);
    expect($item->milestones()->count())->toBe(1);

    $changed = $payload;
    $changed['payload']['pr'] = ['repo' => 'artisan-build/capstan', 'number' => 113, 'url' => 'https://example.test/pull/113'];
    $this->postJson($url, $changed)->assertConflict();
    expect($item->workTask->fresh()->pr)->toBe($pr)
        ->and($item->milestones()->count())->toBe(1);
});

test('signed pr_opened normalizes numeric strings before storage milestone replay and representation', function (): void {
    $item = workerLinkItem();
    $payload = ['kind' => 'pr_opened', 'payload' => ['pr' => [
        'repo' => 'artisan-build/capstan', 'number' => '112', 'url' => 'https://example.test/pull/112',
    ]]];
    $url = resolve(WorkWorkerLinks::class)->forItem($item)['milestone'];

    $this->postJson($url, $payload)->assertCreated();
    $this->postJson($url, $payload)->assertCreated();

    expect($item->workTask->fresh()->pr['number'])->toBeInt()->toBe(112)
        ->and($item->milestones()->sole()->payload['pr']['number'])->toBeInt()->toBe(112)
        ->and(resolve(WorkRepresentation::class)->task($item->workTask->fresh())['pr']['number'])->toBeInt()->toBe(112);
});

test('terminal milestones cannot skip the legal state machine', function (): void {
    $item = workerLinkItem(['state' => 'claimed']);
    $this->postJson(resolve(WorkWorkerLinks::class)->forItem($item)['milestone'], ['kind' => 'done', 'payload' => (object) []])->assertUnprocessable();
    expect($item->milestones()->count())->toBe(0)->and($item->fresh()->state)->toBe(WorkItemState::Claimed);
});

test('release without a nonempty summary retains earlier bounded progress', function (array $payload): void {
    $item = workerLinkItem(['last_progress' => str_repeat('x', 2000)]);
    $this->postJson(resolve(WorkWorkerLinks::class)->forItem($item)['milestone'], ['kind' => 'released', 'payload' => (object) $payload])->assertCreated();
    expect($item->fresh()->last_progress)->toBe(str_repeat('x', 2000))->and($item->fresh()->attempts)->toBe(1);
})->with([[[]], [['summary' => '']], [['summary' => '   ']]]);

test('milestone quota is rolling and item scoped', function (): void {
    $item = workerLinkItem(['lease_until' => now()->addHour()]);
    $item->workTask->update(['timebox_minutes' => 60]);
    $url = resolve(WorkWorkerLinks::class)->forItem($item)['milestone'];
    for ($i = 0; $i < 30; $i++) {
        $this->postJson($url, ['kind' => 'note', 'payload' => (object) []])->assertCreated();
    }
    $this->postJson($url, ['kind' => 'note', 'payload' => (object) []])->assertStatus(429);
    $other = workerLinkItem();
    $this->postJson(resolve(WorkWorkerLinks::class)->forItem($other)['milestone'], ['kind' => 'note', 'payload' => (object) []])->assertCreated();
    Date::setTestNow(now()->addMinutes(10));
    $this->postJson($url, ['kind' => 'note', 'payload' => (object) []])->assertCreated();
    expect($item->milestones()->count())->toBe(31);
});

test('stale callback cannot stamp a brief or append partial milestone effects', function (string $operation): void {
    $item = workerLinkItem();
    $item->workTask->briefRevisions()->create(['sequence' => 1, 'content' => 'new', 'author_actor' => 'creator']);
    $race = fn () => WorkItem::query()->whereKey($item->id)->update(['fence' => 2]);
    try {
        $service = resolve(WorkWorkerService::class);
        $links = resolve(WorkWorkerLinks::class)->forItem($item);
        parse_str(parse_url($links['brief'], PHP_URL_QUERY), $query);
        $operation === 'brief'
            ? $service->brief($item->id, 1, $query['nonce'], null, $race)
            : $service->milestone($item->id, 1, WorkMilestoneKind::Released, ['summary' => 'must not persist'], $race);
        test()->fail('Expected lost claim');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }
    expect($item->fresh()->fetched_brief_revision_id)->toBeNull()->and($item->milestones()->count())->toBe(0)
        ->and($item->transitions()->count())->toBe(0)->and($item->fresh()->attempts)->toBe(0)
        ->and($item->fresh()->last_progress)->toBeNull();
})->with(['brief', 'milestone']);

test('steers scope unread recent order and first read time', function (): void {
    $item = workerLinkItem();
    $other = $item->workTask->items()->create(['role' => 'reviewer']);
    $make = function (array $attrs) use ($item) {
        $steer = $item->workTask->steers()->make(['text' => Str::random(), 'author_actor' => 'supervisor']);
        $steer->forceFill($attrs)->save();

        return $steer;
    };
    $oldUnread = $make(['created_at' => now()->subDays(2)]);
    $itemUnread = $make(['work_item_id' => $item->id]);
    $recent = $make(['read_at' => now()->subHour()]);
    $make(['work_item_id' => $other->id]);
    $make(['created_at' => now()->subDays(2), 'read_at' => now()->subDay()]);
    $url = resolve(WorkWorkerLinks::class)->forItem($item)['steers'];
    $response = $this->getJson($url)->assertOk()->assertJsonCount(3, 'steers');
    expect($response->json('steers.*.id'))->toBe([$oldUnread->id, $itemUnread->id, $recent->id])
        ->and($response->json('steers.0'))->not->toHaveKey('item_id');
    $first = $itemUnread->fresh()->read_at;
    Date::setTestNow(now()->addMinute());
    $this->getJson($url)->assertOk()->assertJsonCount(2, 'steers');
    expect($itemUnread->fresh()->read_at->equalTo($first))->toBeTrue()
        ->and($recent->fresh()->read_at->equalTo(now()->subMinute()->subHour()))->toBeTrue();
});
