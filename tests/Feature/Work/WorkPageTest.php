<?php

use App\Livewire\Postmaster\SpokeMap;
use App\Livewire\Work\Index;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkEscalation;
use App\Models\WorkTask;
use App\Work\WorkOrchestrationService;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['capstan.features.artifacts' => false]);
});

function workPageTask(array $attributes = []): WorkTask
{
    $team = Team::query()->create(['name' => 'Page team', 'slug' => Str::random(16)]);

    return WorkTask::query()->create($attributes + [
        'team_id' => $team->id, 'title' => 'Page-created task', 'creator_actor' => 'page creator',
        'required_capabilities' => [], 'timebox_minutes' => 30,
    ]);
}

function workNavigationTags(string $html): array
{
    preg_match_all('/<a\b[^>]*data-testid="work-navigation"[^>]*>/s', $html, $matches);

    return $matches[0];
}

function workPageXPath(string $html): DOMXPath
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html);

    return new DOMXPath($dom);
}

test('root board derives columns and renders escaped read only tree details', function (): void {
    $team = Team::query()->create(['name' => 'Board team', 'slug' => Str::random(16)]);
    $hostile = '<script data-board="hostile">alert("board")</script>';
    $baseTime = now();

    $queued = workPageTask([
        'team_id' => $team->id, 'title' => 'Queued root '.$hostile, 'owner_actor' => 'queued owner', 'priority' => 'P1',
    ]);
    $queuedChild = workPageTask([
        'team_id' => $team->id, 'parent_task_id' => $queued->id, 'title' => 'Queued child',
    ]);
    $queued->items()->create(['role' => 'terminal queue control', 'state' => 'acted']);
    $queuedChild->items()->create(['role' => 'waiting queue control', 'state' => 'queued']);

    $running = workPageTask([
        'team_id' => $team->id, 'title' => 'Running root', 'owner_actor' => 'running owner', 'priority' => 'P0',
    ]);
    $validChild = workPageTask([
        'team_id' => $team->id, 'parent_task_id' => $running->id, 'title' => 'Linked child '.$hostile,
        'pr' => ['repo' => 'artisan-build/capstan', 'number' => 812, 'url' => 'https://example.test/pull/812'],
    ]);
    $plainChild = workPageTask([
        'team_id' => $team->id, 'parent_task_id' => $running->id, 'title' => 'Unlinked child',
    ]);
    $unsafeChild = workPageTask([
        'team_id' => $team->id, 'parent_task_id' => $running->id, 'title' => 'Unsafe child',
        'pr' => ['repo' => 'artisan-build/capstan', 'number' => 813, 'url' => 'javascript:alert(1)'],
    ]);
    $invalidChild = workPageTask([
        'team_id' => $team->id, 'parent_task_id' => $running->id, 'title' => 'Invalid child',
        'pr' => ['repo' => 'artisan-build/capstan', 'number' => 814, 'url' => 'https:// bad.example/pull/814'],
    ]);

    $deviceSpoke = Spoke::query()->create([
        'actor_id' => 'device actor', 'credential_id' => (string) Str::uuid7(),
        'device_name' => 'Device '.$hostile, 'name' => 'Ignored spoke name',
    ]);
    $nameSpoke = Spoke::query()->create([
        'actor_id' => 'name actor', 'credential_id' => (string) Str::uuid7(), 'name' => 'Spoke '.$hostile,
    ]);
    $this->travelTo($baseTime->copy()->subMinutes(2));
    $rootRunningItem = $running->items()->create([
        'role' => 'root role '.$hostile, 'state' => 'claimed', 'claimant_spoke_id' => $deviceSpoke->id,
        'last_progress' => 'Older progress',
    ]);
    $this->travelTo($baseTime);
    $childRunningItem = $validChild->items()->create([
        'role' => 'child role', 'state' => 'delivered', 'claimant_spoke_id' => $nameSpoke->id,
        'last_progress' => 'Newest progress '.$hostile,
    ]);
    $unavailableItem = $plainChild->items()->create(['role' => 'unavailable role', 'state' => 'routed']);
    $answeredItem = $unsafeChild->items()->create(['role' => 'answered control', 'state' => 'queued']);

    WorkEscalation::query()->create([
        'work_task_id' => $running->id, 'work_item_id' => $rootRunningItem->id, 'request_key' => 'root-open',
        'addressed_actor' => 'person', 'question' => 'Root question '.$hostile, 'metadata' => [],
    ]);
    WorkEscalation::query()->create([
        'work_task_id' => $validChild->id, 'work_item_id' => $childRunningItem->id, 'request_key' => 'child-open',
        'addressed_actor' => 'person', 'question' => 'Child question', 'metadata' => [],
    ]);
    WorkEscalation::query()->create([
        'work_task_id' => $unsafeChild->id, 'work_item_id' => $answeredItem->id, 'request_key' => 'answered',
        'addressed_actor' => 'person', 'question' => 'Answered question', 'metadata' => [],
        'answer' => 'Already answered', 'answered_by' => 'person', 'answered_at' => now(),
    ]);

    $rootEvidence = $rootRunningItem->evidence()->create([
        'work_task_id' => $running->id, 'public_token' => str_repeat('a', 64),
        'storage_key' => 'work-evidence/private/root', 'content_type' => 'text/plain', 'extension' => 'txt', 'size_bytes' => 10,
    ]);
    $childEvidence = $childRunningItem->evidence()->create([
        'work_task_id' => $validChild->id, 'public_token' => str_repeat('b', 64),
        'storage_key' => 'work-evidence/private/child', 'content_type' => 'text/plain', 'extension' => 'txt', 'size_bytes' => 11,
    ]);
    $dependencyA = workPageTask([
        'team_id' => $team->id, 'parent_task_id' => $validChild->id, 'title' => 'Dependency A '.$hostile,
    ]);
    $dependencyB = workPageTask([
        'team_id' => $team->id, 'parent_task_id' => $validChild->id, 'title' => 'Dependency B',
    ]);
    $running->dependencies()->attach($dependencyA);
    $validChild->dependencies()->attach([$dependencyA->id, $dependencyB->id]);

    $finished = workPageTask([
        'team_id' => $team->id, 'title' => 'Finished root', 'owner_actor' => 'finished owner', 'priority' => 'P3',
    ]);
    $finishedChild = workPageTask([
        'team_id' => $team->id, 'parent_task_id' => $finished->id, 'title' => 'Finished child',
    ]);
    $finished->items()->create(['role' => 'finished root role', 'state' => 'acted']);
    $finishedChild->items()->create(['role' => 'finished child role', 'state' => 'failed']);

    $noItems = workPageTask([
        'team_id' => $team->id, 'title' => 'No-item root', 'owner_actor' => 'no-item owner', 'priority' => 'P2',
    ]);
    $noItemChild = workPageTask([
        'team_id' => $team->id, 'parent_task_id' => $noItems->id, 'title' => 'No-item child',
    ]);

    $response = $this->actingAsVersioned(capstanUser())->get(route('work.index'))->assertOk();
    $xpath = workPageXPath($response->content());

    expect($xpath->query('//*[@data-testid="work-board"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-testid="work-column-queued"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-testid="work-column-running"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-testid="work-column-finished"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-testid="work-card"]')->length)->toBe(4);

    foreach ([
        'queued' => [$queued, $noItems],
        'running' => [$running],
        'finished' => [$finished],
    ] as $column => $roots) {
        foreach ($roots as $root) {
            expect($xpath->query('//*[@data-testid="work-column-'.$column.'"]//*[@data-testid="work-card" and @data-task-id="'.$root->id.'"]')->length)->toBe(1);
        }
    }
    foreach ([$queuedChild, $validChild, $plainChild, $unsafeChild, $invalidChild, $finishedChild, $noItemChild, $dependencyA, $dependencyB] as $child) {
        expect($xpath->query('//*[@data-testid="work-card" and @data-task-id="'.$child->id.'"]')->length)->toBe(0);
    }

    foreach ([
        [$queued, 'queued owner', 'P1', 'Queued child'],
        [$running, 'running owner', 'P0', 'Linked child'],
        [$finished, 'finished owner', 'P3', 'Finished child'],
        [$noItems, 'no-item owner', 'P2', 'No-item child'],
    ] as [$root, $owner, $priority, $childTitle]) {
        $card = $xpath->query('//*[@data-testid="work-card" and @data-task-id="'.$root->id.'"]')->item(0);
        expect($card?->textContent)->toContain($root->title, $owner, $priority, $childTitle);
    }

    $runningCard = $xpath->query('//*[@data-testid="work-card" and @data-task-id="'.$running->id.'"]')->item(0);
    $listItems = [];
    foreach ($xpath->query('.//li', $runningCard) as $listItem) {
        $listItems[] = trim($listItem->textContent);
    }
    expect($xpath->query('.//*[@data-testid="work-child"]/a[@href="https://example.test/pull/812" and @rel="noopener noreferrer"]', $runningCard)->length)->toBe(1)
        ->and($xpath->query('.//*[@data-testid="work-child"]/a', $runningCard)->length)->toBe(1)
        ->and($xpath->query('.//*[@data-testid="work-running-item"]', $runningCard)->length)->toBe(3)
        ->and($xpath->query('.//*[@data-testid="work-running-item" and @data-machine-source="device_name"]', $runningCard)->length)->toBe(1)
        ->and($xpath->query('.//*[@data-testid="work-running-item" and @data-machine-source="name"]', $runningCard)->length)->toBe(1)
        ->and($xpath->query('.//*[@data-testid="work-running-item" and @data-machine-source="unavailable"]', $runningCard)->length)->toBe(1)
        ->and(array_count_values($listItems)['Dependency A '.$hostile] ?? 0)->toBe(1)
        ->and(array_count_values($listItems)['Dependency B'] ?? 0)->toBe(1);
    expect($runningCard?->textContent)->toContain(
        'Linked child '.$hostile, 'Unlinked child', 'Unsafe child', 'Invalid child',
        'root role '.$hostile, 'Device '.$hostile, 'child role', 'Spoke '.$hostile, 'unavailable role',
        'Root question '.$hostile, 'Child question', $rootEvidence->id, $childEvidence->id,
        'Dependency A '.$hostile, 'Dependency B', 'Newest progress '.$hostile,
    );
    expect($runningCard?->textContent)->not->toContain('Ignored spoke name');
    expect($runningCard?->textContent)->not->toContain('Answered question');
    expect($runningCard?->textContent)->not->toContain('Older progress');
    expect($xpath->query('.//*[@data-testid="work-detail"]', $runningCard)->length)->toBe(1)
        ->and($xpath->query('.//*[@data-testid="work-task-evidence"]', $runningCard)->length)->toBe(1)
        ->and($xpath->query('.//a[@href="'.route('work.evidence.show', ['token' => $rootEvidence->public_token]).'"]', $runningCard)->length)->toBe(1)
        ->and($xpath->query('.//a[@href="'.route('work.evidence.show', ['token' => $childEvidence->public_token]).'"]', $runningCard)->length)->toBe(1)
        ->and($xpath->query('.//form | .//button | .//input | .//textarea | .//select', $xpath->query('//*[@data-testid="work-board"]')->item(0))->length)->toBe(0);
    $response->assertSee($hostile)->assertDontSee($hostile, false)
        ->assertDontSee('href="javascript:', false)->assertDontSee('href="https:// bad.example', false);
});

test('sidebar and desktop mobile header Work navigation is structurally current', function (): void {
    $user = capstanUser();
    $sidebar = $this->actingAsVersioned($user)->get(route('work.index'))->assertOk();
    $tags = workNavigationTags($sidebar->content());
    expect($tags)->toHaveCount(1)->and($tags[0])->toContain('href="'.route('work.index').'"', 'aria-current="page"');
    Route::get('/_tests/work-header', fn () => view('layouts.app.header', [
        'slot' => new HtmlString('<main data-testid="work-header-body"></main>'),
    ]))->name('work.header-test');
    $header = $this->actingAsVersioned($user)->get('/_tests/work-header')->assertOk();
    expect(workNavigationTags($header->content()))->toHaveCount(1);
    foreach (workNavigationTags($header->content()) as $tag) {
        expect($tag)->toContain('href="'.route('work.index').'"', 'aria-current="page"');
    }
    $dashboard = $this->get(route('bfc.dashboard'))->assertOk();
    expect(workNavigationTags($dashboard->content())[0])->not->toContain('aria-current="page"');
});

test('Work page requires active product admission and guards both mount and later renders', function (): void {
    $this->get(route('work.index'))->assertRedirect();
    $user = capstanUser();
    $this->actingAsVersioned($user);
    $component = Livewire::test(Index::class)->assertOk()->assertSeeHtml('data-testid="work-board"');
    $component->call('$refresh')->assertOk();
    $user->forceFill(['status' => 'inactive'])->save();
    Livewire::test(Index::class)->assertForbidden();
    $user->forceFill(['status' => 'active'])->save();
    OffboardedSubject::query()->create([
        'subject_type' => SubjectType::UserPrincipal, 'subject_ref' => 'capstan-user:'.$user->id,
        'user_id' => (string) $user->id, 'offboarded_at' => now(),
    ]);
    Livewire::test(Index::class)->assertForbidden();
});

test('Work board bounds root cards and eager loads populated trees without per-card queries', function (): void {
    $team = Team::query()->create(['name' => 'Query team', 'slug' => Str::random(16)]);
    foreach (range(1, 27) as $rootNumber) {
        $root = workPageTask(['team_id' => $team->id, 'title' => 'Root '.$rootNumber]);
        $root->items()->create(['role' => 'root-'.$rootNumber, 'state' => 'queued']);
        foreach (range(1, 3) as $childNumber) {
            $child = workPageTask([
                'team_id' => $team->id, 'parent_task_id' => $root->id,
                'title' => 'Child '.$rootNumber.'-'.$childNumber,
            ]);
            $child->items()->create(['role' => 'child-'.$rootNumber.'-'.$childNumber, 'state' => 'queued']);
        }
    }
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'work_')) {
            $queries[] = $query->sql;
        }
    });
    $response = $this->actingAsVersioned(capstanUser())->get(route('work.index'))->assertOk();
    expect(substr_count($response->content(), 'data-testid="work-card"'))->toBe(25)
        ->and(substr_count($response->content(), 'data-testid="work-child"'))->toBe(75)
        ->and(count($queries))->toBeLessThanOrEqual(12);
});

test('addressed users see escaped escalations and page answers recheck feature and admission before waking once', function (): void {
    $user = capstanUser();
    $task = workPageTask();
    $escalation = resolve(WorkOrchestrationService::class)->escalate(
        $task,
        'page-hostile',
        (string) $user->id,
        '<script>question</script>',
        ['<img src=x onerror=alert(1)>', 'Continue'],
        '<b>Continue</b>',
    );
    $other = resolve(WorkOrchestrationService::class)->escalate(
        $task,
        'page-other',
        (string) capstanUser()->id,
        'Foreign question',
        ['No'],
        'No',
    );

    $response = $this->actingAsVersioned($user)->get(route('work.index'))->assertOk();
    $response->assertSee('data-testid="work-escalations"', false)
        ->assertSee('data-testid="work-escalation"', false)
        ->assertSee('<script>question</script>')
        ->assertSee('<img src=x onerror=alert(1)>')
        ->assertSee('<b>Continue</b>')
        ->assertDontSee('<script>question</script>', false)
        ->assertDontSee('<img src=x onerror=alert(1)>', false)
        ->assertDontSee('<b>Continue</b>', false);
    $answerSurface = workPageXPath($response->content())->query('//*[@data-testid="work-escalations"]')->item(0);
    expect($answerSurface?->textContent)->toContain('<script>question</script>')->not->toContain('Foreign question');

    Livewire::actingAs($user)->test(Index::class)
        ->set('answers.'.$escalation->id, '<svg onload=alert(1)>')
        ->call('answerEscalation', $escalation->id)
        ->assertHasNoErrors();
    expect($escalation->fresh()->answer)->toBe('<svg onload=alert(1)>')
        ->and($escalation->fresh()->workItem->transitions()->count())->toBe(1)
        ->and($other->fresh()->answered_at)->toBeNull();
});

test('Work and Postmaster surfaces are available without product feature gates', function (): void {
    $user = capstanUser();
    $this->actingAsVersioned($user);

    Livewire::test(SpokeMap::class)->assertOk();
    $this->get(route('work.index'))->assertOk();
    $this->get('/up')->assertOk();
});
