<?php

use App\Features\Work;
use App\Livewire\Postmaster\SpokeMap;
use App\Livewire\Work\Index;
use App\Models\Team;
use App\Models\WorkTask;
use App\Work\WorkOrchestrationService;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

beforeEach(function (): void {
    config([
        'capstan.features.work' => true,
        'capstan.features.artifacts' => false,
        'capstan.features.postmaster' => false,
    ]);
    Feature::flushCache();
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

test('active members see separate items latest milestones claimant and liveness with escaped stored data', function (): void {
    $hostile = '<script>alert("work")</script><img src=x onerror=alert(1)>';
    $hostileRole = '<b data-x=1>role</b>';
    $task = workPageTask(['title' => 'Title '.$hostile]);
    $task->briefRevisions()->create(['sequence' => 1, 'content' => 'Brief '.$hostile, 'author_actor' => 'creator']);
    $task->steers()->create(['text' => 'Steer '.$hostile, 'author_actor' => 'author '.$hostile]);
    foreach (['code_review', 'security_review'] as $role) {
        $item = $task->items()->create([
            'role' => $role.$hostileRole, 'state' => 'routed', 'claimant_actor' => 'actor '.$hostile,
            'stalled_at' => now(), 'waiting_on_host_at' => now(),
        ]);
        $item->milestones()->create(['fence' => 0, 'kind' => 'note', 'payload' => ['summary' => 'Old '.$role]]);
        $item->milestones()->create(['fence' => 0, 'kind' => 'blocked', 'payload' => ['summary' => $role.' '.$hostile, 'url' => 'javascript:'.$hostile]]);
    }
    $response = $this->actingAsVersioned(capstanUser())->get(route('work.index'))->assertOk();
    $response->assertSee('data-testid="work-board"', false)
        ->assertSee('data-task-id="'.$task->id.'"', false)
        ->assertSee('Title '.$hostile)
        ->assertSee('Brief '.$hostile)->assertSee('Steer '.$hostile)
        ->assertSee('code_review'.$hostileRole)->assertSee('security_review'.$hostileRole)
        ->assertSee('actor '.$hostile)->assertSee('routed')
        ->assertDontSee($hostile, false)
        ->assertDontSee($hostileRole, false)
        ->assertDontSee('Old code_review')->assertDontSee('Old security_review')
        ->assertDontSee('href="javascript:', false);
    $dom = new DOMDocument;
    @$dom->loadHTML($response->content());
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//*[@data-testid="work-task"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-testid="work-item"]')->length)->toBe(2);
    foreach ($xpath->query('//*[@data-testid="work-item"]') as $node) {
        foreach (['state', 'claimant', 'stalled', 'waiting', 'milestone'] as $section) {
            expect($xpath->query('.//*[@data-testid="work-item-'.$section.'"]', $node)->length)->toBe(1);
        }
        expect($node->textContent)->toContain('blocked', $hostile, 'javascript:');
    }
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

test('Work board bounds tasks items and eager loaded milestones without per-item queries', function (): void {
    foreach (range(1, 26) as $i) {
        workPageTask(['title' => 'Additional '.$i]);
    }
    $task = workPageTask();
    foreach (range(1, 27) as $i) {
        $item = $task->items()->create(['role' => 'review-'.$i]);
        $item->milestones()->create(['fence' => 0, 'kind' => 'note', 'payload' => ['summary' => 'Progress '.$i]]);
    }
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'work_')) {
            $queries[] = $query->sql;
        }
    });
    $response = $this->actingAsVersioned(capstanUser())->get(route('work.index'))->assertOk();
    expect(substr_count($response->content(), 'data-testid="work-task"'))->toBe(25)
        ->and(substr_count($response->content(), 'data-testid="work-item"'))->toBe(25)
        ->and(count($queries))->toBe(6);
    $response->assertSee('data-testid="work-task-truncated"', false)->assertSee('Progress 1')->assertDontSee('Progress 27');
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
        ->assertDontSee('<b>Continue</b>', false)
        ->assertDontSee('Foreign question');

    Livewire::actingAs($user)->test(Index::class)
        ->set('answers.'.$escalation->id, '<svg onload=alert(1)>')
        ->call('answerEscalation', $escalation->id)
        ->assertHasNoErrors();
    expect($escalation->fresh()->answer)->toBe('<svg onload=alert(1)>')
        ->and($escalation->fresh()->workItem->transitions()->count())->toBe(1)
        ->and($other->fresh()->answered_at)->toBeNull();
});

test('obsolete Work config changes neither Work nor other surfaces', function (): void {
    config(['capstan.features.artifacts' => true, 'capstan.features.postmaster' => true]);
    Feature::flushCache();
    $user = capstanUser();
    $this->actingAsVersioned($user);

    $probe = function (): array {
        try {
            Livewire::test(SpokeMap::class)->assertOk();
            $postmaster = 'ok';
        } catch (Throwable $exception) {
            $postmaster = $exception::class;
        }

        return ['postmaster' => $postmaster, 'health' => $this->get('/up')->getStatusCode()];
    };

    $withWork = $probe();
    expect($withWork)->toBe(['postmaster' => 'ok', 'health' => 200]);

    config(['capstan.features.work' => false]);
    Feature::flushCache();

    expect($probe())->toBe($withWork)
        ->and(Feature::active(Work::class))->toBeTrue()
        ->and($this->get(route('work.index'))->assertOk());
});
