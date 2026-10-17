<?php

use App\Auth\CapstanCredentialDeclaration;
use App\Enums\WorkItemState;
use App\Mcp\CapstanServer;
use App\Mcp\WriteFaultInjector;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkBriefRevision;
use App\Models\WorkItem;
use App\Models\WorkSteer;
use App\Models\WorkTask;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\BuiltForCloud\User;
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
    foreach (['create_task', 'revise_brief', 'queue_item', 'cancel_item', 'list_work', 'get_task', 'steer', 'cancel_task'] as $tool) {
        $args = ['team_id' => $team->id, 'task_id' => $task['id'], 'idempotency_key' => 'denied-'.$tool];
        $args = match ($tool) {
            'create_task' => ['team_id' => $team->id, 'title' => 'Denied', 'brief' => 'Denied', 'idempotency_key' => 'denied'],
            'cancel_item' => ['team_id' => $team->id, 'item_id' => (string) Str::uuid7(), 'idempotency_key' => 'denied'],
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
    foreach (['create_task', 'revise_brief', 'queue_item', 'cancel_item', 'list_work', 'get_task', 'steer', 'cancel_task'] as $tool) {
        workMcpCall($tool, (object) [], $token)->assertOk()
            ->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', 'Work is unavailable.');
    }
    expect(DB::table('mcp_write_claims')->count())->toBe(0)->and(WorkTask::query()->count())->toBe(0);
    workMcpCall('postmaster_spokes', (object) [], $token)->assertOk()->assertJsonPath('result.content.0.text', 'Postmaster is unavailable.');
});

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
