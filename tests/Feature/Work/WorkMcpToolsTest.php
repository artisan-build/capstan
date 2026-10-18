<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Enums\WorkItemState;
use App\Mcp\CapstanServer;
use App\Mcp\Tools\OrchestrateTool;
use App\Mcp\WriteFaultInjector;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkBriefRevision;
use App\Models\WorkEscalation;
use App\Models\WorkItem;
use App\Models\WorkOrchestrationDecision;
use App\Models\WorkOrchestrationEvent;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkStateRevision;
use App\Models\WorkSteer;
use App\Models\WorkTask;
use App\Models\WorkWake;
use App\Work\WorkOrchestrationService;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'capstan.features.work' => true,
        'capstan.features.postmaster' => false,
        'capstan.features.artifacts' => false,
        'capstan.work.lease_seconds' => 300,
    ]);
    Feature::flushCache();
});

function workMcpTeam(array $attributes = []): Team
{
    return Team::query()->create($attributes + ['name' => 'MCP team', 'slug' => Str::random(16)]);
}

function workMcpToken(User $user): string
{
    $token = Str::random(40);
    Credential::query()->create([
        'kind' => CredentialKind::Bearer, 'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::UserPrincipal, 'subject_ref' => 'capstan-user:'.$user->id,
        'user_id' => (string) $user->id, 'name' => 'Work test', 'abilities' => [],
        'secret_hash' => hash('sha256', $token), 'status' => CredentialStatus::Active,
    ]);

    return $token;
}

function workMcpCall(string $tool, array|object $arguments, string $token): TestResponse
{
    return test()->postJson('/mcp/write', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ], ['Authorization' => 'Bearer '.$token]);
}

function workMcpList(string $token, string $path = '/mcp/write'): array
{
    return test()->postJson($path, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
    ], ['Authorization' => 'Bearer '.$token])->assertOk()->json('result.tools');
}

function workMcpResult(string $tool, array $arguments, string $token): array
{
    $response = workMcpCall($tool, $arguments, $token)->assertOk();
    $result = $response->json('result.structuredContent');
    expect($result)->toBeArray();
    $definition = resolve('App\\Mcp\\Tools\\'.Str::studly($tool).'Tool')->toArray();
    assertWorkSchema(json_decode($response->content())->result->structuredContent, $definition['outputSchema']);

    return $result;
}

function workMcpCreate(Team $team, string $token, array $attributes = []): array
{
    return workMcpResult('create_task', $attributes + [
        'team_id' => $team->id, 'idempotency_key' => (string) Str::uuid7(),
        'title' => 'MCP-created task', 'brief' => 'MCP-created brief',
    ], $token);
}

function assertWorkSchema(mixed $value, array $schema): void
{
    if (isset($schema['enum'])) {
        expect($value)->toBeIn($schema['enum']);
    }
    switch ($schema['type']) {
        case 'object':
            expect($value)->toBeInstanceOf(stdClass::class);
            $properties = (array) ($schema['properties'] ?? []);
            expect(array_diff(array_keys(get_object_vars($value)), array_keys($properties)))->toBe([]);
            foreach ($schema['required'] ?? [] as $required) {
                expect(property_exists($value, $required))->toBeTrue();
            }
            foreach (get_object_vars($value) as $key => $child) {
                assertWorkSchema($child, $properties[$key]);
            }
            break;
        case 'array':
            expect($value)->toBeArray()->and(array_is_list($value))->toBeTrue();
            foreach ($value as $child) {
                assertWorkSchema($child, $schema['items']);
            }
            break;
        case 'integer': expect($value)->toBeInt();
            break;
        case 'boolean': expect($value)->toBeBool();
            break;
        case 'string': expect($value)->toBeString();
            break;
        default: throw new RuntimeException('Unhandled schema type');
    }
}

test('creator addressing pool defaults and explicit addresses persist with UUIDv7 revisions', function (): void {
    $user = capstanUser(['role' => 'owner']);
    $token = workMcpToken($user);
    $other = capstanUser();
    foreach ([false, true] as $pool) {
        $team = workMcpTeam(['pool_work' => $pool]);
        $result = workMcpCreate($team, $token);
        expect($result['outcome'])->toBe('accepted')
            ->and($result['revision']['sequence'])->toBe(1)
            ->and($result['task']['id'][14])->toBe('7')
            ->and($result['revision']['id'][14])->toBe('7');
        if ($pool) {
            expect($result['task'])->not->toHaveKey('user_actor_address');
        } else {
            expect($result['task']['user_actor_address'])->toBe((string) $user->id);
        }
        $explicit = workMcpCreate($team, $token, ['user_actor_address' => (string) $other->id]);
        expect($explicit['task']['user_actor_address'])->toBe((string) $other->id);
    }
});

test('device pins require explicit failover and unpinned work follows team defaults', function (): void {
    $user = capstanUser(['role' => 'admin']);
    $token = workMcpToken($user);
    $spoke = Spoke::query()->create(['actor_id' => (string) $user->id, 'credential_id' => (string) Str::uuid7()]);
    foreach ([true, false] as $default) {
        $team = workMcpTeam(['default_failover' => $default]);
        expect(workMcpCreate($team, $token)['task']['failover'])->toBe($default);
        $before = WorkTask::query()->count();
        workMcpCall('create_task', [
            'team_id' => $team->id, 'idempotency_key' => Str::random(), 'title' => 'Pinned',
            'brief' => 'Pin brief', 'device_spoke_id' => $spoke->id,
        ], $token)->assertOk()->assertJsonPath('result.isError', true);
        expect(WorkTask::query()->count())->toBe($before);
        foreach ([true, false] as $failover) {
            $task = workMcpCreate($team, $token, ['device_spoke_id' => $spoke->id, 'failover' => $failover])['task'];
            expect($task['device_spoke_id'])->toBe($spoke->id)->and($task['failover'])->toBe($failover);
        }
    }
});

test('MCP queue refuses reserved roles on repeated requests without item effects', function (string $role): void {
    $token = workMcpToken(capstanUser(['role' => 'owner']));
    $team = workMcpTeam();
    $task = workMcpCreate($team, $token)['task'];
    foreach (['first', 'second', 'first'] as $key) {
        $result = workMcpResult('queue_item', ['team_id' => $team->id, 'task_id' => $task['id'], 'role' => $role, 'idempotency_key' => $key], $token);
        expect($result)->toBe(['outcome' => 'refused', 'reason' => 'reserved_role']);
        expect(WorkItem::query()->count())->toBe(0)->and(WorkEscalation::query()->count())->toBe(0);
    }
})->with(['orchestrator', 'human']);

test('leaf writes replay once including after accepted settlement failure and publish exact success schemas', function (): void {
    $token = workMcpToken(capstanUser(['role' => 'owner']));
    $team = workMcpTeam();
    $observed = [];
    $created = workMcpCreate($team, $token, ['idempotency_key' => 'created']);
    $observed['create_task'][] = $created;
    $observed['create_task'][] = workMcpCreate($team, $token, ['idempotency_key' => 'created']);
    expect($observed['create_task'][1]['outcome'])->toBe('already_accepted')
        ->and(WorkTask::query()->count())->toBe(1)->and(WorkBriefRevision::query()->count())->toBe(1);
    $base = ['team_id' => $team->id, 'task_id' => $created['task']['id']];
    foreach ([
        'revise_brief' => ['brief' => 'Revised brief'],
        'queue_item' => ['role' => 'code_review'],
        'steer' => ['content' => 'A task-wide steer'],
    ] as $tool => $extra) {
        $input = $base + $extra + ['idempotency_key' => $tool];
        $observed[$tool][] = workMcpResult($tool, $input, $token);
        $observed[$tool][] = workMcpResult($tool, $input, $token);
        expect($observed[$tool][1]['outcome'])->toBe('already_accepted');
    }
    $itemId = $observed['queue_item'][0]['item']['id'];
    $item = WorkItem::query()->findOrFail($itemId);
    $item->milestones()->create(['fence' => 0, 'kind' => 'note', 'payload' => []]);
    $item->milestones()->create(['fence' => 0, 'kind' => 'note', 'payload' => ['summary' => 'Latest milestone', 'url' => 'https://example.test/pr']]);
    $item->update(['stalled_at' => now(), 'waiting_on_host_at' => now(), 'claimant_actor' => 'test actor']);
    $specific = workMcpResult('steer', $base + ['item_id' => $itemId, 'content' => 'Item-specific steer', 'idempotency_key' => 'specific'], $token);
    $observed['steer'][] = $specific;
    $observed['list_work'][] = workMcpResult('list_work', $base + ['limit' => 1, 'state' => 'queued'], $token);
    $detail = workMcpResult('get_task', $base, $token);
    $observed['get_task'][] = $detail;
    expect(array_column($detail['revisions'], 'sequence'))->toBe([1, 2])
        ->and($detail['items'][0]['latest_milestone']['payload']['summary'])->toBe('Latest milestone')
        ->and($detail['steers'])->toHaveCount(2);

    resolve(WriteFaultInjector::class); // Replace only the fault seam, not the durability implementation.
    $this->mock(WriteFaultInjector::class, function ($mock): void {
        $mock->shouldReceive('beforeClaim', 'beforeOperation')->andReturnNull();
        $mock->shouldReceive('beforeSettlement')->andThrow(new RuntimeException('settlement fault'));
        $mock->shouldReceive('beforeAcceptedRecoverySettlement')->andReturnNull();
    });
    $cancel = ['team_id' => $team->id, 'item_id' => $itemId, 'idempotency_key' => 'cancel'];
    $observed['cancel_item'][] = workMcpResult('cancel_item', $cancel, $token);
    $observed['cancel_item'][] = workMcpResult('cancel_item', $cancel, $token);
    expect($item->transitions()->count())->toBe(1);
    $this->forgetMock(WriteFaultInjector::class);
    $cancelTask = $base + ['idempotency_key' => 'cancel-task'];
    $observed['cancel_task'][] = workMcpResult('cancel_task', $cancelTask, $token);
    $observed['cancel_task'][] = workMcpResult('cancel_task', $cancelTask, $token);
    $tools = collect(workMcpList($token))->keyBy('name');
    McpDelegatedTools::assertConforms(CapstanServer::class);
    foreach ($observed as $tool => $responses) {
        $definition = $tools[$tool];
        expect($definition['inputSchema']['additionalProperties'])->toBeFalse()
            ->and($definition['outputSchema']['additionalProperties'])->toBeFalse();
        expect($responses)->not->toBeEmpty();
    }
});

test('all ID-taking tools scope real positive targets and refuse other teams like missing targets', function (string $tool): void {
    $token = workMcpToken(capstanUser(['role' => 'owner']));
    $team = workMcpTeam();
    $other = workMcpTeam();
    $task = workMcpCreate($team, $token)['task'];
    $item = workMcpResult('queue_item', ['team_id' => $team->id, 'task_id' => $task['id'], 'role' => 'builder', 'idempotency_key' => 'seed-item'], $token)['item'];
    $target = $tool === 'cancel_item' ? ['item_id' => $item['id']] : ['task_id' => $task['id']];
    $extra = match ($tool) {
        'revise_brief' => ['brief' => 'Updated'], 'queue_item' => ['role' => 'security_review'],
        'steer' => ['item_id' => $item['id'], 'content' => 'Scoped steer'], default => [],
    };
    $read = in_array($tool, ['list_work', 'get_task'], true);
    $before = [$task, WorkItem::query()->count(), WorkBriefRevision::query()->count(), WorkSteer::query()->count()];
    $cross = workMcpCall($tool, $target + $extra + ['team_id' => $other->id] + ($read ? [] : ['idempotency_key' => 'cross']), $token);
    if ($read) {
        $cross->assertOk()->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', 'Work task is unavailable.');
    } else {
        $cross->assertOk()->assertJsonPath('result.structuredContent.outcome', 'refused')->assertJsonPath('result.structuredContent.reason', 'task_unavailable');
    }
    expect([WorkTask::query()->findOrFail($task['id'])->cancelled_at, WorkItem::query()->count(), WorkBriefRevision::query()->count(), WorkSteer::query()->count()])
        ->toBe([null, $before[1], $before[2], $before[3]]);
    $positive = workMcpResult($tool, $target + $extra + ['team_id' => $team->id] + ($read ? [] : ['idempotency_key' => 'positive']), $token);
    if (! $read) {
        expect($positive['outcome'])->toBe('accepted');
    } else {
        expect($positive)->toHaveKey($tool === 'get_task' ? 'task' : 'groups');
    }
})->with(['revise_brief', 'queue_item', 'cancel_item', 'steer', 'cancel_task', 'list_work', 'get_task']);

test('steer cannot attach a same-team item to another task and terminal targets refuse cleanly', function (): void {
    $token = workMcpToken(capstanUser(['role' => 'admin']));
    $team = workMcpTeam();
    $task = workMcpCreate($team, $token)['task'];
    $otherTask = workMcpCreate($team, $token)['task'];
    $base = ['team_id' => $team->id, 'task_id' => $task['id']];
    $item = workMcpResult('queue_item', $base + ['role' => 'builder', 'idempotency_key' => 'item'], $token)['item'];
    expect(workMcpResult('steer', ['team_id' => $team->id, 'task_id' => $otherTask['id'], 'item_id' => $item['id'], 'content' => 'wrong task', 'idempotency_key' => 'wrong'], $token)['outcome'])->toBe('refused');
    expect(WorkSteer::query()->count())->toBe(0);
    workMcpResult('cancel_task', $base + ['idempotency_key' => 'cancel'], $token);
    foreach (['queue_item' => ['role' => 'builder'], 'cancel_task' => []] as $tool => $extra) {
        expect(workMcpResult($tool, $base + $extra + ['idempotency_key' => $tool], $token)['outcome'])->toBe('refused');
    }
    expect(workMcpResult('cancel_item', ['team_id' => $team->id, 'item_id' => $item['id'], 'idempotency_key' => 'terminal'], $token)['outcome'])->toBe('refused');
    expect(WorkItem::query()->findOrFail($item['id'])->transitions()->count())->toBe(1);
});

test('missing IDs and foreign item steers are opaque and replay keeps empty milestone objects and original defaults', function (): void {
    $token = workMcpToken(capstanUser(['role' => 'owner']));
    $team = workMcpTeam(['pool_work' => false, 'default_failover' => true]);
    $first = workMcpCreate($team, $token, ['idempotency_key' => 'stable-defaults']);
    $team->update(['pool_work' => true, 'default_failover' => false]);
    $replay = workMcpCreate($team, $token, ['idempotency_key' => 'stable-defaults']);
    expect($replay['task'])->toBe($first['task']);
    expect(workMcpCreate($team, $token, ['idempotency_key' => 'stable-defaults', 'title' => 'Changed'])['outcome'])->toBe('refused');
    $taskId = $first['task']['id'];
    $base = ['team_id' => $team->id, 'task_id' => $taskId];
    $item = workMcpResult('queue_item', $base + ['role' => 'builder', 'idempotency_key' => 'empty-payload'], $token)['item'];
    WorkItem::query()->findOrFail($item['id'])->milestones()->create(['fence' => 0, 'kind' => 'note', 'payload' => []]);
    $cancel = ['team_id' => $team->id, 'item_id' => $item['id'], 'idempotency_key' => 'empty-cancel'];
    workMcpResult('cancel_item', $cancel, $token);
    workMcpResult('cancel_item', $cancel, $token); // Helper validates the actual JSON object type on replay.
    $foreign = workMcpCreate(workMcpTeam(), $token)['task'];
    $foreignItem = workMcpResult('queue_item', ['team_id' => $foreign['team_id'], 'task_id' => $foreign['id'], 'role' => 'builder', 'idempotency_key' => 'foreign-item'], $token)['item'];
    foreach ([$foreignItem['id'], (string) Str::uuid7()] as $id) {
        $refused = workMcpResult('steer', $base + ['item_id' => $id, 'content' => 'Wrong item', 'idempotency_key' => $id], $token);
        expect($refused)->toBe(['outcome' => 'refused', 'reason' => 'item_unavailable']);
    }
    foreach (['get_task', 'list_work', 'revise_brief', 'queue_item', 'steer', 'cancel_task', 'cancel_item'] as $tool) {
        $args = ['team_id' => $team->id];
        $args[$tool === 'cancel_item' ? 'item_id' : 'task_id'] = (string) Str::uuid7();
        if (! in_array($tool, ['get_task', 'list_work'], true)) {
            $args += ['idempotency_key' => 'missing-'.$tool];
        }
        $args += match ($tool) {
            'revise_brief' => ['brief' => 'Missing'], 'queue_item' => ['role' => 'builder'], 'steer' => ['content' => 'Missing'], default => [],
        };
        $response = workMcpCall($tool, $args, $token)->assertOk();
        if (in_array($tool, ['get_task', 'list_work'], true)) {
            $response->assertJsonPath('result.content.0.text', 'Work task is unavailable.');
        } else {
            $response->assertJsonPath('result.structuredContent.reason', 'task_unavailable');
        }
    }
    expect(WorkSteer::query()->count())->toBe(0);
});

test('every Work tool rejects non-admitted or non-manager callers without effects', function (string $condition): void {
    $user = capstanUser(['role' => 'owner']);
    $token = workMcpToken($user);
    $team = workMcpTeam();
    $task = workMcpCreate($team, $token)['task'];
    if ($condition === 'offboarded') {
        OffboardedSubject::query()->create(['subject_type' => SubjectType::UserPrincipal, 'subject_ref' => 'capstan-user:'.$user->id, 'user_id' => (string) $user->id, 'offboarded_at' => now()]);
    } elseif ($condition === 'missing') {
        $user->delete();
    } else {
        $user->forceFill($condition === 'member' ? ['role' => 'member'] : ['status' => 'inactive'])->save();
    }
    $writes = DB::table('mcp_write_claims')->count();
    foreach (['create_task', 'revise_brief', 'queue_item', 'cancel_item', 'list_work', 'get_task', 'steer', 'cancel_task', 'take_seat', 'release_seat', 'list_escalations', 'answer_escalation', 'orchestrate'] as $tool) {
        $args = ['team_id' => $team->id, 'task_id' => $task['id'], 'idempotency_key' => 'denied-'.$tool];
        $args = match ($tool) {
            'create_task' => ['team_id' => $team->id, 'title' => 'Denied', 'brief' => 'Denied', 'idempotency_key' => 'denied'],
            'cancel_item' => ['team_id' => $team->id, 'item_id' => (string) Str::uuid7(), 'idempotency_key' => 'denied'],
            'take_seat' => ['team_id' => $team->id, 'task_id' => $task['id'], 'session_ref' => 'denied', 'idempotency_key' => 'denied'],
            'release_seat' => ['team_id' => $team->id, 'task_id' => $task['id'], 'seat_fence' => 1, 'idempotency_key' => 'denied'],
            'list_escalations' => ['team_id' => $team->id],
            'answer_escalation' => ['team_id' => $team->id, 'escalation_id' => (string) Str::uuid7(), 'answer' => 'Denied', 'idempotency_key' => 'denied'],
            'orchestrate' => ['team_id' => $team->id, 'task_id' => $task['id'], 'seat_fence' => 1, 'session_ref' => 'denied', 'action' => 'finish', 'input' => ['state' => 'acted'], 'idempotency_key' => 'denied'],
            'revise_brief' => $args + ['brief' => 'Denied'], 'queue_item' => $args + ['role' => 'builder'],
            'steer' => $args + ['content' => 'Denied'],
            'get_task', 'list_work' => ['team_id' => $team->id, 'task_id' => $task['id']], default => $args,
        };
        $response = workMcpCall($tool, $args, $token);
        expect($response->json('result.structuredContent'))->toBeNull();
        expect($response->status() !== 200 || $response->json('result.isError') === true)->toBeTrue();
    }
    expect(DB::table('mcp_write_claims')->count())->toBe($writes)->and(WorkTask::query()->count())->toBe(1);
})->with(['member', 'inactive', 'offboarded', 'missing']);

test('Work OFF errors every new tool without a write and leaves existing tool definitions unchanged', function (): void {
    $token = workMcpToken(capstanUser(['role' => 'owner']));
    $on = workMcpList($token);
    config(['capstan.features.work' => false]);
    Feature::flushCache();
    expect(workMcpList($token))->toBe($on);
    foreach (['create_task', 'revise_brief', 'queue_item', 'cancel_item', 'list_work', 'get_task', 'steer', 'cancel_task', 'take_seat', 'release_seat', 'list_escalations', 'answer_escalation', 'orchestrate'] as $tool) {
        workMcpCall($tool, (object) [], $token)->assertOk()
            ->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', 'Work is unavailable.');
    }
    expect(DB::table('mcp_write_claims')->count())->toBe(0)->and(WorkTask::query()->count())->toBe(0);
    workMcpCall('postmaster_spokes', (object) [], $token)->assertOk()->assertJsonPath('result.content.0.text', 'Postmaster is unavailable.');
});

test('interactive MCP seats renew for one credential exclude drains expose bounded events and release with fencing', function (): void {
    $user = capstanUser(['role' => 'owner']);
    $token = workMcpToken($user);
    $team = workMcpTeam(['pool_work' => true]);
    $task = workMcpCreate($team, $token)['task'];
    $parent = WorkTask::query()->findOrFail($task['id']);
    foreach (range(1, 27) as $index) {
        resolve(WorkOrchestrationService::class)->event($parent, 'mcp-'.$index, 'test_event', ['index' => $index]);
    }

    $taken = workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'opaque first',
        'after' => 0, 'idempotency_key' => 'take-one',
    ], $token);
    expect($taken['outcome'])->toBe('accepted')->and($taken['events'])->toHaveCount(25)
        ->and($taken['has_more'])->toBeTrue()->and($taken['seat']['session_ref'])->toBe('opaque first');
    $fence = $taken['seat']['seat_fence'];
    $renewed = workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'opaque renewed',
        'after' => 25, 'idempotency_key' => 'take-two',
    ], $token);
    expect($renewed['seat']['seat_fence'])->toBe($fence)->and($renewed['seat']['session_ref'])->toBe('opaque renewed')
        ->and($renewed['events'])->toHaveCount(2)->and($renewed['has_more'])->toBeFalse();
    expect(workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'opaque renewed',
        'after' => 999, 'idempotency_key' => 'invalid-cursor',
    ], $token))->toBe(['outcome' => 'refused', 'reason' => 'invalid_cursor']);

    $drain = capstanBoundBearer($user, CapstanCredentialDeclaration::WORK_DRAIN);
    $headers = ['Authorization' => 'Bearer '.$drain['token'], CapstanCredentialDeclaration::ACTOR_HEADER => (string) $user->id];
    $this->withHeaders($headers)->postJson(route('api.work.claim', $team), [
        'capacity' => 1, 'roles' => ['orchestrator'], 'capabilities' => [],
    ])->assertOk()->assertJsonCount(0, 'items');

    $other = capstanUser(['role' => 'admin']);
    $otherToken = workMcpToken($other);
    $refused = workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'opaque renewed',
        'idempotency_key' => 'foreign-take',
    ], $otherToken);
    expect($refused)->toBe(['outcome' => 'refused', 'reason' => 'seat_unavailable']);

    expect(workMcpResult('release_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'seat_fence' => $fence + 1,
        'idempotency_key' => 'stale-release',
    ], $token))->toBe(['outcome' => 'refused', 'reason' => 'seat_authority_lost']);
    $released = workMcpResult('release_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'seat_fence' => $fence,
        'idempotency_key' => 'release',
    ], $token);
    expect($released['released'])->toBeTrue()->and($released['seat_fence'])->toBeGreaterThan($fence);
    expect(workMcpResult('orchestrate', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'seat_fence' => $fence,
        'session_ref' => 'opaque renewed', 'action' => 'finish', 'input' => ['state' => 'acted', 'content' => 'Stale state'],
        'idempotency_key' => 'released-action',
    ], $token))->toBe(['outcome' => 'refused', 'reason' => 'orchestration_forbidden']);
    $this->withHeaders($headers)->postJson(route('api.work.claim', $team), [
        'capacity' => 1, 'roles' => ['orchestrator'], 'capabilities' => [],
    ])->assertOk()->assertJsonCount(1, 'items');
});

test('cold interactive takes clamp default cursors after a completed turn without accepting invalid cursors', function (): void {
    $user = capstanUser(['role' => 'owner']);
    $token = workMcpToken($user);
    $team = workMcpTeam(['pool_work' => true]);
    $parent = WorkTask::query()->findOrFail(workMcpCreate($team, $token)['task']['id']);
    resolve(WorkOrchestrationService::class)->event($parent, 'completed-turn', 'test_event', ['step' => 'completed']);

    $initial = workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'completed-turn',
        'idempotency_key' => 'completed-turn-take',
    ], $token);
    workMcpResult('orchestrate', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'spoke_id' => $initial['seat']['spoke_id'],
        'seat_fence' => $initial['seat']['seat_fence'], 'session_ref' => 'completed-turn',
        'action' => 'finish', 'input' => ['state' => 'acted', 'content' => 'Completed state'],
        'idempotency_key' => 'completed-turn-finish',
    ], $token);
    workMcpResult('release_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'spoke_id' => $initial['seat']['spoke_id'],
        'seat_fence' => $initial['seat']['seat_fence'], 'idempotency_key' => 'completed-turn-release',
    ], $token);

    $later = resolve(WorkOrchestrationService::class)->event($parent, 'after-completed-turn', 'test_event', ['step' => 'later']);
    $released = WorkOrchestratorSeat::query()->where('work_task_id', $parent->id)->sole();
    $aboveUpper = [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'invalid-cold-take',
        'after' => $released->event_sequence + 1, 'idempotency_key' => 'invalid-cold-take',
    ];
    expect(workMcpResult('take_seat', $aboveUpper, $token))
        ->toBe(['outcome' => 'refused', 'reason' => 'invalid_cursor'])
        ->and(workMcpResult('take_seat', $aboveUpper, $token))
        ->toBe(['outcome' => 'refused', 'reason' => 'invalid_cursor']);
    $afterRefusal = $released->fresh();
    expect($afterRefusal->fence)->toBe($released->fence)
        ->and($afterRefusal->holder_actor)->toBeNull()
        ->and($afterRefusal->holder_spoke_id)->toBeNull()
        ->and($afterRefusal->session_ref)->toBeNull()
        ->and($afterRefusal->interactive)->toBeFalse();

    $omitted = workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'omitted-cold-take',
        'idempotency_key' => 'omitted-cold-take',
    ], $token);
    expect($omitted['outcome'])->toBe('accepted')
        ->and($omitted['seat']['cursor'])->toBe(1)
        ->and($omitted['events'])->toHaveCount(1)
        ->and($omitted['events'][0]['id'])->toBe($later->id)
        ->and($omitted['context']['cursor'])->toBe(1)
        ->and($omitted['context']['events'])->toHaveCount(1)
        ->and($omitted['context']['events'][0]['id'])->toBe($later->id);
    workMcpResult('release_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'spoke_id' => $omitted['seat']['spoke_id'],
        'seat_fence' => $omitted['seat']['seat_fence'], 'idempotency_key' => 'omitted-cold-release',
    ], $token);

    $explicitZero = workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'zero-cold-take',
        'after' => 0, 'idempotency_key' => 'zero-cold-take',
    ], $token);
    expect($explicitZero['outcome'])->toBe('accepted')
        ->and($explicitZero['events'])->toHaveCount(1)
        ->and($explicitZero['events'][0]['id'])->toBe($later->id)
        ->and($explicitZero['context']['cursor'])->toBe(1)
        ->and($explicitZero['context']['events'])->toHaveCount(1)
        ->and($explicitZero['context']['events'][0]['id'])->toBe($later->id);
});

test('interactive MCP context and exact action envelope exercise the full subtree capability with replay fencing', function (): void {
    $user = capstanUser(['role' => 'owner']);
    $token = workMcpToken($user);
    $team = workMcpTeam(['pool_work' => true]);
    $parent = WorkTask::query()->findOrFail(workMcpCreate($team, $token)['task']['id']);
    $child = WorkTask::query()->create([
        'team_id' => $team->id, 'parent_task_id' => $parent->id, 'title' => 'Existing child',
        'required_capabilities' => [], 'timebox_minutes' => 10, 'creator_actor' => (string) $user->id,
    ]);
    $event = resolve(WorkOrchestrationService::class)->event($parent, 'interactive-actions', 'test_event', ['safe' => true]);
    $taken = workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'interactive-actions',
        'idempotency_key' => 'interactive-actions-take',
    ], $token);
    expect($taken['context']['task']['id'])->toBe($parent->id)
        ->and($taken['context']['brief']['content'])->toBe('MCP-created brief')
        ->and($taken['context']['events'][0]['id'])->toBe($event->id);
    $base = [
        'team_id' => $team->id, 'task_id' => $parent->id, 'spoke_id' => $taken['seat']['spoke_id'],
        'seat_fence' => $taken['seat']['seat_fence'], 'session_ref' => 'interactive-actions',
    ];
    $call = (fn (string $action, array $input, string $key): array => workMcpResult('orchestrate', $base + [
        'action' => $action, 'input' => $input, 'idempotency_key' => $key,
    ], $token));
    $created = $call('create_child', ['under_task_id' => $parent->id, 'title' => 'Created child', 'brief' => 'Created brief'], 'interactive-create');
    $call('revise_brief', ['task_id' => $child->id, 'brief' => 'Revised child'], 'interactive-revise');
    $queued = $call('queue_item', ['task_id' => $child->id, 'role' => 'builder'], 'interactive-queue');
    expect($call('queue_item', ['task_id' => $child->id, 'role' => 'builder'], 'interactive-queue')['outcome'])->toBe('already_accepted');
    $call('steer', ['task_id' => $child->id, 'item_id' => $queued['item_id'], 'content' => 'Keep scope'], 'interactive-steer');
    $call('cancel_item', ['item_id' => $queued['item_id']], 'interactive-cancel');
    $call('decide', ['event_id' => $event->id, 'decision' => 'Proceed', 'rationale' => 'Bounded evidence', 'evidence' => ['source' => 'test']], 'interactive-decide');
    $call('request_wake', ['key' => 'interactive-wake', 'due_at' => now()->addMinute()->toJSON(), 'reason' => 'Follow up'], 'interactive-wake');
    $call('escalate', ['key' => 'interactive-escalate', 'addressed_actor' => (string) $user->id, 'question' => 'Continue?', 'options' => ['Yes'], 'recommendation' => 'Yes'], 'interactive-escalate');
    $finished = $call('finish', ['state' => 'acted', 'content' => 'Interactive state'], 'interactive-finish');
    resolve(WorkOrchestrationService::class)->event($parent, 'interactive-failed', 'test_event', ['safe' => true]);
    $failed = $call('finish', ['state' => 'failed'], 'interactive-failed');

    expect($created['task_id'])->not->toBe($parent->id)
        ->and($finished['status'])->toBe('completed')
        ->and($failed['status'])->toBe('completed')
        ->and(WorkOrchestratorSeat::query()->where('work_task_id', $parent->id)->sole()->children_created)->toBe(1)
        ->and(WorkStateRevision::query()->where('work_task_id', $parent->id)->count())->toBe(1);

    $before = WorkItem::query()->count();
    $stale = $base;
    $stale['session_ref'] = 'spoofed';
    $refused = workMcpResult('orchestrate', $stale + [
        'action' => 'queue_item', 'input' => ['task_id' => $child->id, 'role' => 'reviewer'], 'idempotency_key' => 'interactive-spoofed',
    ], $token);
    expect($refused)->toBe(['outcome' => 'refused', 'reason' => 'orchestration_forbidden'])
        ->and(WorkItem::query()->count())->toBe($before);
});

test('interactive MCP acted finish refuses blank state before effects and permits a corrected retry', function (array $invalidInput): void {
    $user = capstanUser(['role' => 'owner']);
    $token = workMcpToken($user);
    $team = workMcpTeam(['pool_work' => true]);
    $parent = WorkTask::query()->findOrFail(workMcpCreate($team, $token)['task']['id']);
    resolve(WorkOrchestrationService::class)->event($parent, 'mcp-finish-state', 'test_event');
    $taken = workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'mcp-finish-state',
        'idempotency_key' => 'mcp-finish-state-take',
    ], $token);
    $base = [
        'team_id' => $team->id, 'task_id' => $parent->id, 'spoke_id' => $taken['seat']['spoke_id'],
        'seat_fence' => $taken['seat']['seat_fence'], 'session_ref' => 'mcp-finish-state',
        'action' => 'finish', 'idempotency_key' => 'mcp-finish-state',
    ];
    $seat = fn (): WorkOrchestratorSeat => WorkOrchestratorSeat::query()->where('work_task_id', $parent->id)->sole();
    $turn = WorkItem::query()->findOrFail($seat()->active_item_id);
    $snapshot = fn (): array => [
        WorkStateRevision::query()->where('work_task_id', $parent->id)->count(),
        $seat()->completed_cursor,
        $turn->fresh()->state,
        $turn->transitions()->count(),
        DB::table('mcp_write_claims')->count(),
    ];
    $before = $snapshot();

    $response = workMcpCall('orchestrate', $base + ['input' => $invalidInput], $token)->assertOk();
    expect($response->json('result.structuredContent'))->toBe(['outcome' => 'refused', 'reason' => 'invalid_orchestration_action']);
    expect($snapshot())->toBe($before);

    $finished = workMcpResult('orchestrate', $base + [
        'input' => ['state' => 'acted', 'content' => 'Corrected interactive state'],
    ], $token);
    $revision = WorkStateRevision::query()->where('work_task_id', $parent->id)->sole();
    expect($finished['outcome'])->toBe('accepted')
        ->and($turn->fresh()->state)->toBe(WorkItemState::Acted)
        ->and($revision->content)->toBe('Corrected interactive state')
        ->and($revision->through_cursor)->toBe(1)
        ->and($seat()->completed_cursor)->toBe(1)
        ->and(DB::table('mcp_write_claims')->count())->toBe($before[4] + 1);

    resolve(WorkOrchestrationService::class)->event($parent, 'mcp-failed-without-content', 'test_event');
    $failedItem = WorkItem::query()->findOrFail($seat()->active_item_id);
    $failed = workMcpResult('orchestrate', array_replace($base, [
        'input' => ['state' => 'failed'], 'idempotency_key' => 'mcp-failed-without-content',
    ]), $token);
    expect($failed['outcome'])->toBe('accepted')
        ->and($failedItem->fresh()->state)->toBe(WorkItemState::Failed)
        ->and(WorkStateRevision::query()->where('work_task_id', $parent->id)->count())->toBe(1)
        ->and($seat()->completed_cursor)->toBe(1);
})->with([
    'omitted content' => [['state' => 'acted']],
    'empty content' => [['state' => 'acted', 'content' => '']],
]);

test('MCP orchestrate refuses an invalid action input readably without internal disclosure and still accepts the corrected call', function (): void {
    $user = capstanUser(['role' => 'owner']);
    $token = workMcpToken($user);
    $team = workMcpTeam(['pool_work' => true]);
    $parent = WorkTask::query()->findOrFail(workMcpCreate($team, $token)['task']['id']);
    resolve(WorkOrchestrationService::class)->event($parent, 'mcp-invalid-input', 'test_event');
    $taken = workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'mcp-invalid-input',
        'idempotency_key' => 'mcp-invalid-input-take',
    ], $token);
    $base = [
        'team_id' => $team->id, 'task_id' => $parent->id, 'spoke_id' => $taken['seat']['spoke_id'],
        'seat_fence' => $taken['seat']['seat_fence'], 'session_ref' => 'mcp-invalid-input', 'action' => 'escalate',
    ];
    $input = [
        'addressed_actor' => (string) $user->id, 'question' => 'Canary question '.Str::random(12).'?',
        'options' => ['Canary option '.Str::random(12)], 'recommendation' => 'Canary recommendation '.Str::random(12),
    ];
    $before = [WorkEscalation::query()->count(), WorkItem::query()->count(), DB::table('mcp_write_claims')->count()];

    $response = workMcpCall('orchestrate', $base + ['input' => $input, 'idempotency_key' => 'mcp-invalid-input-missing-key'], $token)->assertOk();
    $refused = $response->json('result.structuredContent');
    $definition = resolve(OrchestrateTool::class)->toArray();
    assertWorkSchema(json_decode($response->content())->result->structuredContent, $definition['outputSchema']);
    expect($refused)->toBe(['outcome' => 'refused', 'reason' => 'invalid_orchestration_action'])
        ->and($response->json('result.isError'))->toBeFalse()
        ->and($response->json('error'))->toBeNull()
        ->and($response->json('result.content.0.text'))->not->toBe('An internal server error occurred.')
        ->and([WorkEscalation::query()->count(), WorkItem::query()->count(), DB::table('mcp_write_claims')->count()])->toBe($before);
    $body = $response->content();
    expect($body)->not->toContain('.php');
    expect($body)->not->toContain('WorkOrchestrationSurface');
    expect($body)->not->toContain('App\\\\');
    expect($body)->not->toContain('trace');
    expect($body)->not->toContain('line');
    expect($body)->not->toContain('field is required');
    expect($body)->not->toContain($input['question']);
    expect($body)->not->toContain($input['options'][0]);
    expect($body)->not->toContain($input['recommendation']);
    expect($body)->not->toContain('mcp-invalid-input-missing-key');

    $accepted = workMcpResult('orchestrate', $base + [
        'input' => $input + ['key' => 'mcp-invalid-input-corrected'], 'idempotency_key' => 'mcp-invalid-input-corrected',
    ], $token);
    expect($accepted['outcome'])->toBe('accepted')
        ->and(WorkEscalation::query()->count())->toBe($before[0] + 1)
        ->and(DB::table('mcp_write_claims')->count())->toBe($before[2] + 1);
});

test('MCP orchestrate refuses a wake due outside the horizon readably and still accepts one at the ceiling', function (Closure $dueAt): void {
    Date::setTestNow(now()->startOfSecond());
    $user = capstanUser(['role' => 'owner']);
    $token = workMcpToken($user);
    $team = workMcpTeam(['pool_work' => true]);
    $parent = WorkTask::query()->findOrFail(workMcpCreate($team, $token)['task']['id']);
    resolve(WorkOrchestrationService::class)->event($parent, 'mcp-wake-horizon', 'test_event');
    $taken = workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'mcp-wake-horizon',
        'idempotency_key' => 'mcp-wake-horizon-take',
    ], $token);
    $base = [
        'team_id' => $team->id, 'task_id' => $parent->id, 'spoke_id' => $taken['seat']['spoke_id'],
        'seat_fence' => $taken['seat']['seat_fence'], 'session_ref' => 'mcp-wake-horizon', 'action' => 'request_wake',
    ];
    $horizon = (int) config('capstan.work.orchestration.wake_horizon_days');
    $reason = 'Canary wake reason '.Str::random(12);
    $before = [WorkWake::query()->count(), DB::table('mcp_write_claims')->count()];

    $response = workMcpCall('orchestrate', $base + [
        'input' => ['key' => 'mcp-wake-horizon-refused', 'due_at' => $dueAt($horizon), 'reason' => $reason],
        'idempotency_key' => 'mcp-wake-horizon-refused',
    ], $token)->assertOk();
    $definition = resolve(OrchestrateTool::class)->toArray();
    assertWorkSchema(json_decode($response->content())->result->structuredContent, $definition['outputSchema']);
    expect($response->json('result.structuredContent'))->toBe(['outcome' => 'refused', 'reason' => 'wake_outside_horizon'])
        ->and($response->json('result.isError'))->toBeFalse()
        ->and($response->json('error'))->toBeNull()
        ->and($response->json('result.content.0.text'))->not->toBe('An internal server error occurred.')
        ->and([WorkWake::query()->count(), DB::table('mcp_write_claims')->count()])->toBe($before);
    $body = $response->content();
    expect($body)->not->toContain('.php');
    expect($body)->not->toContain('WorkOrchestrationSurface');
    expect($body)->not->toContain('App\\\\');
    expect($body)->not->toContain('trace');
    expect($body)->not->toContain('line');
    expect($body)->not->toContain('wake_horizon_days');
    expect($body)->not->toContain($reason);

    $accepted = workMcpResult('orchestrate', $base + [
        'input' => ['key' => 'mcp-wake-horizon-ceiling', 'due_at' => now()->addDays($horizon)->toJSON(), 'reason' => $reason],
        'idempotency_key' => 'mcp-wake-horizon-ceiling',
    ], $token);
    $wake = WorkWake::query()->where('work_task_id', $parent->id)->sole();
    expect($accepted['outcome'])->toBe('accepted')
        ->and($wake->due_at->equalTo(now()->addDays($horizon)))->toBeTrue()
        ->and(WorkWake::query()->count())->toBe($before[0] + 1);
})->with([
    'production value far beyond the ceiling' => [fn (int $horizon): string => '2099-12-31T23:59:59Z'],
    'one second beyond the ceiling' => [fn (int $horizon): string => now()->addDays($horizon)->addSecond()->toJSON()],
    'one second in the past' => [fn (int $horizon): string => now()->subSecond()->toJSON()],
]);

test('MCP child orchestration enforces canonical intent and team defaults before durable effects', function (): void {
    $user = capstanUser(['role' => 'owner']);
    $token = workMcpToken($user);
    $team = workMcpTeam(['pool_work' => true]);
    $parent = WorkTask::query()->findOrFail(workMcpCreate($team, $token)['task']['id']);
    resolve(WorkOrchestrationService::class)->event($parent, 'mcp-child-contract', 'test_event');
    $taken = workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'mcp-child-contract',
        'idempotency_key' => 'mcp-child-contract-take',
    ], $token);
    $base = [
        'team_id' => $team->id, 'task_id' => $parent->id, 'spoke_id' => $taken['seat']['spoke_id'],
        'seat_fence' => $taken['seat']['seat_fence'], 'session_ref' => 'mcp-child-contract', 'action' => 'create_child',
    ];
    $active = capstanUser();
    $inactive = capstanUser(['status' => 'inactive']);
    $spoke = Spoke::query()->create(['actor_id' => (string) $active->id, 'credential_id' => (string) Str::uuid7()]);
    $orphan = Spoke::query()->create(['actor_id' => '999999999', 'credential_id' => (string) Str::uuid7()]);
    $call = fn (array $extra, string $key) => workMcpCall('orchestrate', $base + [
        'input' => array_replace(['under_task_id' => $parent->id, 'title' => 'Child', 'brief' => 'Brief'], $extra),
        'idempotency_key' => $key,
    ], $token);
    $refuse = function (array $extra, string $key) use ($call): void {
        $before = [WorkTask::query()->count(), WorkBriefRevision::query()->count(), DB::table('mcp_write_claims')->count()];
        $response = $call($extra, $key);
        expect($response->status() !== 200 || $response->json('result.isError') === true || $response->json('error') !== null
            || $response->json('result.structuredContent') === ['outcome' => 'refused', 'reason' => 'invalid_orchestration_action'])->toBeTrue();
        expect([WorkTask::query()->count(), WorkBriefRevision::query()->count(), DB::table('mcp_write_claims')->count()])->toBe($before);
    };

    $refuse(['device_spoke_id' => $spoke->id], 'mcp-pin-no-failover');
    $refuse(['device_spoke_id' => 999999999, 'failover' => true], 'mcp-missing-spoke');
    $refuse(['device_spoke_id' => $orphan->id, 'failover' => true], 'mcp-invalid-spoke');
    $refuse(['user_actor_address' => 'missing'], 'mcp-invalid-actor');
    $refuse(['user_actor_address' => '999999999'], 'mcp-missing-actor');
    $refuse(['user_actor_address' => (string) $inactive->id], 'mcp-inactive-actor');
    $refuse(['brief' => ''], 'mcp-empty-brief');
    $refuse(['brief' => str_repeat('b', 32001)], 'mcp-brief-overrun');
    $refuse(['timebox_minutes' => 0], 'mcp-timebox-underrun');
    $refuse(['timebox_minutes' => 1441], 'mcp-timebox-overrun');
    $refuse(['required_capabilities' => ['duplicate', 'duplicate']], 'mcp-capabilities-duplicate');
    $refuse(['required_capabilities' => [str_repeat('c', 65)]], 'mcp-capability-length');
    $refuse(['required_capabilities' => array_map(fn (int $i): string => 'cap-'.$i, range(1, 26))], 'mcp-capabilities-overrun');

    foreach ([false, true] as $default) {
        $team->update(['default_failover' => $default]);
        $capabilities = array_map(fn (int $i): string => 'cap-'.$i, range(1, 25));
        $result = workMcpResult('orchestrate', $base + [
            'input' => [
                'under_task_id' => $parent->id, 'title' => 'Boundary child', 'brief' => str_repeat('b', 32000),
                'user_actor_address' => (string) $active->id, 'timebox_minutes' => 1440, 'required_capabilities' => $capabilities,
            ],
            'idempotency_key' => 'mcp-default-'.(int) $default,
        ], $token);
        $child = WorkTask::query()->findOrFail($result['task_id']);
        expect($child->failover)->toBe($default)->and($child->timebox_minutes)->toBe(1440)
            ->and($child->required_capabilities)->toBe($capabilities)
            ->and($child->latestBriefRevision()->firstOrFail()->content)->toHaveLength(32000);
    }
});

test('MCP decision context paginates deterministically without omissions or duplicates', function (): void {
    $user = capstanUser(['role' => 'owner']);
    $token = workMcpToken($user);
    $team = workMcpTeam(['pool_work' => true]);
    $parent = WorkTask::query()->findOrFail(workMcpCreate($team, $token)['task']['id']);
    $event = resolve(WorkOrchestrationService::class)->event($parent, 'mcp-decision-pages', 'test_event');
    foreach (range(1, 27) as $index) {
        WorkOrchestrationDecision::query()->create([
            'work_task_id' => $parent->id, 'triggering_event_id' => $event->id,
            'decision' => 'Decision '.$index, 'rationale' => 'Rationale '.$index, 'evidence' => ['index' => $index],
        ]);
    }
    $expected = WorkOrchestrationDecision::query()->where('work_task_id', $parent->id)->orderBy('id')->pluck('id')->all();
    $first = workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'mcp-decision-pages',
        'idempotency_key' => 'mcp-decision-page-one',
    ], $token);
    expect($first['context']['decisions'])->toHaveCount(25)
        ->and($first['context']['decisions_has_more'])->toBeTrue()
        ->and($first['context']['decisions_next_cursor'])->toBe($expected[24]);
    $second = workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'mcp-decision-pages',
        'decisions_after' => $first['context']['decisions_next_cursor'],
        'idempotency_key' => 'mcp-decision-page-two',
    ], $token);
    expect($second['context']['decisions'])->toHaveCount(2)
        ->and($second['context']['decisions_has_more'])->toBeFalse()
        ->and(array_column([...$first['context']['decisions'], ...$second['context']['decisions']], 'id'))->toBe($expected);
});

test('MCP evidence action and addressed-user bounds refuse before durable effects', function (): void {
    $user = capstanUser(['role' => 'owner']);
    $addressed = capstanUser();
    $inactive = capstanUser(['status' => 'inactive']);
    $token = workMcpToken($user);
    $team = workMcpTeam(['pool_work' => true]);
    $parent = WorkTask::query()->findOrFail(workMcpCreate($team, $token)['task']['id']);
    $child = WorkTask::query()->create([
        'team_id' => $team->id, 'parent_task_id' => $parent->id, 'title' => 'Bounds child',
        'required_capabilities' => [], 'timebox_minutes' => 30, 'creator_actor' => (string) $user->id,
    ]);
    $event = resolve(WorkOrchestrationService::class)->event($parent, 'mcp-input-bounds', 'test_event');
    $taken = workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'mcp-input-bounds',
        'idempotency_key' => 'mcp-input-bounds-take',
    ], $token);
    $base = [
        'team_id' => $team->id, 'task_id' => $parent->id, 'spoke_id' => $taken['seat']['spoke_id'],
        'seat_fence' => $taken['seat']['seat_fence'], 'session_ref' => 'mcp-input-bounds',
    ];
    $call = fn (string $action, array $input, string $key) => workMcpCall('orchestrate', $base + compact('action', 'input') + ['idempotency_key' => $key], $token);
    $result = workMcpResult('orchestrate', $base + [
        'action' => 'decide',
        'input' => ['event_id' => $event->id, 'decision' => 'Proceed', 'rationale' => 'Structured', 'evidence' => ['source' => ['kind' => 'test', 'indexes' => [1, 2, 3]]]],
        'idempotency_key' => 'mcp-valid-evidence',
    ], $token);
    expect($result['outcome'])->toBe('accepted')
        ->and(WorkOrchestrationDecision::query()->latest('created_at')->firstOrFail()->evidence)->toBe(['source' => ['kind' => 'test', 'indexes' => [1, 2, 3]]]);

    $deep = ['value' => true];
    foreach (range(1, 7) as $level) {
        $deep = ['level' => $deep];
    }
    foreach ([
        'string' => ['value' => str_repeat('x', 8001)],
        'depth' => $deep,
        'list-root' => ['first', 'second'],
        'encoded-bytes' => ['first' => str_repeat('a', 4100), 'second' => str_repeat('b', 4100)],
    ] as $key => $evidence) {
        $before = [WorkOrchestrationDecision::query()->count(), DB::table('mcp_write_claims')->count()];
        $response = $call('decide', ['event_id' => $event->id, 'decision' => 'No', 'rationale' => 'Invalid', 'evidence' => $evidence], 'mcp-evidence-'.$key);
        expect($response->status() !== 200 || $response->json('result.isError') === true || $response->json('error') !== null
            || $response->json('result.structuredContent') === ['outcome' => 'refused', 'reason' => 'invalid_orchestration_action'])->toBeTrue();
        expect([WorkOrchestrationDecision::query()->count(), DB::table('mcp_write_claims')->count()])->toBe($before);
    }

    expect(workMcpResult('orchestrate', $base + ['action' => 'revise_brief', 'input' => ['task_id' => $child->id, 'brief' => str_repeat('r', 32000)], 'idempotency_key' => 'mcp-revise-boundary'], $token)['outcome'])->toBe('accepted');
    expect(workMcpResult('orchestrate', $base + ['action' => 'steer', 'input' => ['task_id' => $child->id, 'content' => str_repeat('s', 8000)], 'idempotency_key' => 'mcp-steer-boundary'], $token)['outcome'])->toBe('accepted');
    foreach ([
        ['revise_brief', ['task_id' => $child->id, 'brief' => str_repeat('r', 32001)], 'mcp-revise-over'],
        ['steer', ['task_id' => $child->id, 'content' => str_repeat('s', 8001)], 'mcp-steer-over'],
        ['escalate', ['key' => 'missing', 'addressed_actor' => '999999999', 'question' => 'Missing?', 'options' => ['No'], 'recommendation' => 'No'], 'mcp-escalate-missing'],
        ['escalate', ['key' => 'inactive', 'addressed_actor' => (string) $inactive->id, 'question' => 'Inactive?', 'options' => ['No'], 'recommendation' => 'No'], 'mcp-escalate-inactive'],
    ] as [$action, $input, $key]) {
        $before = [WorkBriefRevision::query()->count(), WorkSteer::query()->count(), WorkEscalation::query()->count(), WorkItem::query()->count(), DB::table('mcp_write_claims')->count()];
        $response = $call($action, $input, $key);
        expect($response->status() !== 200 || $response->json('result.isError') === true || $response->json('error') !== null
            || $response->json('result.structuredContent') === ['outcome' => 'refused', 'reason' => 'invalid_orchestration_action'])->toBeTrue();
        expect([WorkBriefRevision::query()->count(), WorkSteer::query()->count(), WorkEscalation::query()->count(), WorkItem::query()->count(), DB::table('mcp_write_claims')->count()])->toBe($before);
    }
    expect(workMcpResult('orchestrate', $base + [
        'action' => 'escalate',
        'input' => ['key' => 'valid', 'addressed_actor' => (string) $addressed->id, 'question' => 'Valid?', 'options' => ['Yes'], 'recommendation' => 'Yes'],
        'idempotency_key' => 'mcp-escalate-valid',
    ], $token)['outcome'])->toBe('accepted');
    expect(WorkEscalation::query()->where('request_key', 'valid')->sole()->addressed_actor)->toBe((string) $addressed->id);

    $definition = collect(workMcpList($token))->firstWhere('name', 'orchestrate')['inputSchema']['properties']['input']['properties'];
    expect($definition['brief']['maxLength'])->toBe(32000)
        ->and($definition['title']['maxLength'])->toBe(255)
        ->and($definition['addressed_actor']['maxLength'])->toBe(19)
        ->and($definition['timebox_minutes']['maximum'])->toBe(1440)
        ->and($definition['required_capabilities']['maxItems'])->toBe(25)
        ->and($definition['options']['maxItems'])->toBe(10);
});

test('interactive take respects a non failover device pin', function (): void {
    $user = capstanUser(['role' => 'owner']);
    $token = workMcpToken($user);
    $team = workMcpTeam(['pool_work' => true]);
    $pin = Spoke::query()->create(['actor_id' => (string) $user->id, 'credential_id' => (string) Str::uuid7()]);
    $task = workMcpCreate($team, $token, ['device_spoke_id' => $pin->id, 'failover' => false])['task'];
    resolve(WorkOrchestrationService::class)->event(WorkTask::query()->findOrFail($task['id']), 'pin', 'test_event');
    expect(workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $task['id'], 'session_ref' => 'off pin',
        'idempotency_key' => 'off-pin',
    ], $token))->toBe(['outcome' => 'refused', 'reason' => 'seat_unavailable']);
    expect(WorkOrchestratorSeat::query()->sole()->holder_actor)->toBeNull();
    $taken = workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $task['id'], 'spoke_id' => $pin->id,
        'session_ref' => 'on pin', 'idempotency_key' => 'on-pin',
    ], $token);
    expect($taken['seat']['spoke_id'])->toBe($pin->id)->and($taken['seat']['session_ref'])->toBe('on pin');

    $foreign = Spoke::query()->create(['actor_id' => (string) capstanUser()->id, 'credential_id' => (string) Str::uuid7()]);
    expect(workMcpResult('release_seat', [
        'team_id' => $team->id, 'task_id' => $task['id'], 'spoke_id' => $foreign->id,
        'seat_fence' => $taken['seat']['seat_fence'], 'idempotency_key' => 'spoofed-spoke',
    ], $token))->toBe(['outcome' => 'refused', 'reason' => 'spoke_unavailable']);
});

test('replacement interactive holder fences old MCP action and context authority', function (): void {
    config(['capstan.work.lease_seconds' => 10]);
    $first = capstanUser(['role' => 'owner']);
    $second = capstanUser(['role' => 'admin']);
    $firstToken = workMcpToken($first);
    $secondToken = workMcpToken($second);
    $team = workMcpTeam(['pool_work' => true]);
    $parent = WorkTask::query()->findOrFail(workMcpCreate($team, $firstToken)['task']['id']);
    $child = WorkTask::query()->create([
        'team_id' => $team->id, 'parent_task_id' => $parent->id, 'title' => 'Replacement child',
        'required_capabilities' => [], 'timebox_minutes' => 10, 'creator_actor' => (string) $first->id,
    ]);
    resolve(WorkOrchestrationService::class)->event($parent, 'replacement', 'test_event');
    $old = workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'old-session', 'idempotency_key' => 'old-take',
    ], $firstToken);
    Date::setTestNow(now()->addSeconds(301));
    $replacement = workMcpResult('take_seat', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'session_ref' => 'new-session', 'idempotency_key' => 'new-take',
    ], $secondToken);
    expect($replacement['seat']['seat_fence'])->toBeGreaterThan($old['seat']['seat_fence']);
    $oldAction = workMcpResult('orchestrate', [
        'team_id' => $team->id, 'task_id' => $parent->id, 'seat_fence' => $old['seat']['seat_fence'],
        'session_ref' => 'old-session', 'action' => 'queue_item', 'input' => ['task_id' => $child->id, 'role' => 'builder'],
        'idempotency_key' => 'old-action',
    ], $firstToken);
    expect($oldAction)->toBe(['outcome' => 'refused', 'reason' => 'orchestration_forbidden'])
        ->and($child->items()->count())->toBe(0);
});

test('MCP escalation listing is addressed and bounded and answers act and wake exactly once', function (): void {
    $user = capstanUser(['role' => 'owner']);
    $other = capstanUser(['role' => 'admin']);
    $token = workMcpToken($user);
    $team = workMcpTeam();
    $parent = WorkTask::query()->findOrFail(workMcpCreate($team, $token)['task']['id']);
    $engine = resolve(WorkOrchestrationService::class);
    $mine = $engine->escalate($parent, 'mine', (string) $user->id, '<script>question</script>', ['One', 'Two'], '<b>One</b>');
    $engine->escalate($parent, 'foreign', (string) $other->id, 'Foreign', ['No'], 'No');
    $listed = workMcpResult('list_escalations', ['team_id' => $team->id, 'limit' => 1], $token);
    expect($listed['escalations'])->toHaveCount(1)->and($listed['escalations'][0]['id'])->toBe($mine->id)
        ->and($listed['escalations'][0]['question'])->toBe('<script>question</script>');

    $args = [
        'team_id' => $team->id, 'escalation_id' => $mine->id, 'answer' => '<img src=x onerror=alert(1)>',
        'idempotency_key' => 'answer',
    ];
    expect(workMcpResult('answer_escalation', $args, $token)['outcome'])->toBe('accepted');
    expect(workMcpResult('answer_escalation', $args, $token)['outcome'])->toBe('already_accepted');
    expect($mine->fresh()->answer)->toBe('<img src=x onerror=alert(1)>')
        ->and(WorkItem::query()->findOrFail($mine->work_item_id)->transitions()->count())->toBe(1)
        ->and(WorkOrchestrationEvent::query()->where('source_key', 'answer:'.$mine->id)->count())->toBe(1)
        ->and(workMcpResult('list_escalations', ['team_id' => $team->id], $token)['escalations'])->toBe([]);
});

test('new orchestration MCP tools reject unknown and wrongly typed arguments before effects', function (string $tool, array $arguments): void {
    $user = capstanUser(['role' => 'owner']);
    $token = workMcpToken($user);
    $team = workMcpTeam();
    $task = workMcpCreate($team, $token)['task'];
    $arguments = array_replace([
        'team_id' => $team->id,
        'task_id' => $task['id'],
        'session_ref' => 'strict',
        'idempotency_key' => 'strict-'.$tool,
    ], $arguments);
    $before = [WorkOrchestratorSeat::query()->count(), WorkEscalation::query()->count(), DB::table('mcp_write_claims')->count()];
    $response = workMcpCall($tool, $arguments, $token);
    expect($response->status() !== 200 || $response->json('result.isError') === true)->toBeTrue();
    expect([WorkOrchestratorSeat::query()->count(), WorkEscalation::query()->count(), DB::table('mcp_write_claims')->count()])->toBe($before);
})->with([
    ['take_seat', ['team_id' => '1']],
    ['take_seat', ['unknown' => true]],
    ['release_seat', ['session_ref' => null, 'seat_fence' => '1']],
    ['answer_escalation', ['task_id' => null, 'session_ref' => null, 'escalation_id' => 123, 'answer' => 'No']],
    ['orchestrate', ['action' => 'queue_item', 'input' => ['unknown' => true]]],
]);

test('runtime input checking refuses unknown and wrong shape values with no partial effects', function (string $tool, array $extra): void {
    $token = workMcpToken(capstanUser(['role' => 'owner']));
    $team = workMcpTeam();
    $task = workMcpCreate($team, $token)['task'];
    $base = match ($tool) {
        'create_task' => ['title' => 'Created', 'brief' => 'Brief'],
        'queue_item' => ['task_id' => $task['id'], 'role' => 'builder'],
        'list_work' => [], default => ['task_id' => $task['id']],
    };
    $base += ['team_id' => $team->id];
    if ($tool !== 'list_work') {
        $base['idempotency_key'] = 'invalid';
    }
    $response = workMcpCall($tool, array_replace($base, $extra), $token);
    expect($response->status() !== 200 || $response->json('result.isError') === true)->toBeTrue();
    expect(WorkTask::query()->count())->toBe(1)->and(WorkItem::query()->count())->toBe(0)
        ->and(DB::table('mcp_write_claims')->count())->toBe(1);
})->with([
    ['create_task', ['unknown' => true]], ['create_task', ['team_id' => '1']],
    ['create_task', ['team_id' => 999999]],
    ['create_task', ['required_capabilities' => (object) ['0' => 'php']]],
    ['create_task', ['required_capabilities' => ['php', 'php']]],
    ['create_task', ['required_capabilities' => [123]]], ['create_task', ['title' => '']],
    ['create_task', ['required_capabilities' => array_map(fn ($i) => 'cap-'.$i, range(1, 26))]],
    ['create_task', ['title' => str_repeat('x', 256)]], ['create_task', ['brief' => str_repeat('x', 32001)]],
    ['create_task', ['device_spoke_id' => 999999, 'failover' => true]],
    ['create_task', ['user_actor_address' => '999999']], ['create_task', ['failover' => 1]],
    ['create_task', ['timebox_minutes' => 0]], ['queue_item', ['role' => ['code_review', 'security_review']]],
    ['list_work', ['limit' => 26]], ['list_work', ['state' => 'released']], ['list_work', ['limit' => null]],
]);

test('list_work is bounded ordered filtered and includes only the latest milestone per item', function (): void {
    $token = workMcpToken(capstanUser(['role' => 'owner']));
    $team = workMcpTeam();
    $task = workMcpCreate($team, $token)['task'];
    $model = WorkTask::query()->findOrFail($task['id']);
    $items = collect(range(1, 27))->map(fn () => $model->items()->create(['role' => 'builder']));
    foreach ($items->take(2) as $item) {
        $item->milestones()->create(['fence' => 0, 'kind' => 'note', 'payload' => ['summary' => 'Old']]);
        $item->milestones()->create(['fence' => 0, 'kind' => 'note', 'payload' => ['summary' => 'New '.$item->id]]);
    }
    $board = workMcpResult('list_work', ['team_id' => $team->id, 'limit' => 2], $token);
    expect($board['has_more'])->toBeTrue()->and($board['limit'])->toBe(2)
        ->and(array_column($board['groups']['queued'], 'id'))->toBe($items->take(2)->pluck('id')->all());
    foreach ($board['groups']['queued'] as $item) {
        expect($item['latest_milestone']['payload']['summary'])->toBe('New '.$item['id']);
    }
    expect(workMcpResult('list_work', ['team_id' => $team->id, 'state' => 'acted'], $token)['groups']['queued'])->toBe([]);
});

test('independent dual review items can be claimed renewed and completed by separate drains', function (): void {
    $user = capstanUser(['role' => 'owner']);
    $token = workMcpToken($user);
    $team = workMcpTeam();
    $task = workMcpCreate($team, $token)['task'];
    $ids = [];
    foreach (['code_review', 'security_review'] as $role) {
        $item = workMcpResult('queue_item', ['team_id' => $team->id, 'task_id' => $task['id'], 'role' => $role, 'idempotency_key' => $role], $token)['item'];
        $ids[] = $item['id'];
        $drain = capstanBoundBearer($user, CapstanCredentialDeclaration::WORK_DRAIN);
        $headers = ['Authorization' => 'Bearer '.$drain['token'], CapstanCredentialDeclaration::ACTOR_HEADER => (string) $user->id];
        $claim = $this->withHeaders($headers)->postJson(route('api.work.claim', $team), ['capacity' => 1, 'roles' => [$role], 'capabilities' => []])
            ->assertOk()->assertJsonPath('items.0.id', $item['id']);
        $this->withHeaders($headers)->postJson(route('api.work.renew', $team), ['items' => [['item_id' => $item['id'], 'fence' => 1]]])->assertOk()->assertJsonPath('results.0.status', 'renewed');
        foreach (['delivered', 'routed'] as $state) {
            $this->withHeaders($headers)->postJson(route('api.work.transition', $team), ['item_id' => $item['id'], 'fence' => 1, 'state' => $state])->assertOk();
        }
        $this->postJson($claim->json('items.0.links.milestone'), ['kind' => 'done', 'payload' => (object) ['summary' => $role.' complete']])->assertCreated();
    }
    $items = WorkItem::query()->whereKey($ids)->get();
    expect($items->pluck('state')->all())->toBe([WorkItemState::Acted, WorkItemState::Acted])
        ->and($items->pluck('claimant_spoke_id')->unique())->toHaveCount(2)
        ->and($items->pluck('attempts')->all())->toBe([0, 0]);
});

test('MCP cancellation revokes active signed links and queued eligibility and task cancellation records every event', function (): void {
    $user = capstanUser(['role' => 'owner']);
    $token = workMcpToken($user);
    $team = workMcpTeam();
    $task = workMcpCreate($team, $token)['task'];
    $base = ['team_id' => $team->id, 'task_id' => $task['id']];
    $ids = [];
    foreach (range(1, 3) as $n) {
        $ids[] = workMcpResult('queue_item', $base + ['role' => 'builder', 'idempotency_key' => 'item'.$n], $token)['item']['id'];
    }
    $drain = capstanBoundBearer($user, CapstanCredentialDeclaration::WORK_DRAIN);
    $headers = ['Authorization' => 'Bearer '.$drain['token'], CapstanCredentialDeclaration::ACTOR_HEADER => (string) $user->id];
    $claim = $this->withHeaders($headers)->postJson(route('api.work.claim', $team), ['capacity' => 1, 'roles' => ['builder'], 'capabilities' => []])->assertOk();
    foreach (array_slice($ids, 0, 2) as $id) {
        workMcpResult('cancel_item', ['team_id' => $team->id, 'item_id' => $id, 'idempotency_key' => 'cancel'.$id], $token);
    }
    foreach (['brief', 'steers'] as $action) {
        $this->getJson($claim->json('items.0.links.'.$action))->assertGone();
    }
    $this->postJson($claim->json('items.0.links.milestone'), ['kind' => 'note', 'payload' => (object) []])->assertGone();
    $this->withHeaders($headers)->postJson(route('api.work.transition', $team), ['item_id' => $ids[0], 'fence' => 1, 'state' => 'delivered'])->assertConflict();
    $this->withHeaders($headers)->postJson(route('api.work.claim', $team), ['capacity' => 25, 'roles' => ['builder'], 'capabilities' => []])->assertOk()->assertJsonPath('items.0.id', $ids[2])->assertJsonCount(1, 'items');
    workMcpResult('cancel_task', $base + ['idempotency_key' => 'cancel-task'], $token);
    foreach ($ids as $id) {
        $item = WorkItem::query()->findOrFail($id);
        expect($item->state)->toBe(WorkItemState::Cancelled)
            ->and($item->transitions()->where('to_state', 'cancelled')->count())->toBe(1);
    }
});
