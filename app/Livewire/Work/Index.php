<?php

namespace App\Livewire\Work;

use App\Machine\TeamMemberships;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkEscalation;
use App\Models\WorkItem;
use App\Models\WorkTask;
use App\Work\TeamWorkPolicy;
use App\Work\WorkOrchestrationService;
use ArtisanBuild\BuiltForCloud\Contracts\IdentityContext;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts::app')]
class Index extends Component
{
    /** @var array<string, string> */
    public array $answers = [];

    public function mount(): void
    {
        $this->guardAccess();
    }

    public function render(): View
    {
        $this->guardAccess();
        $actor = (string) request()->user()->getKey();
        /** @var User $user */
        $user = request()->user();
        $tasks = WorkTask::query()->whereNull('parent_task_id')
            ->whereIn('team_id', resolve(TeamMemberships::class)->teamsFor($user)->select('id'))
            ->orderByDesc('id')->limit(25)
            ->with([
                'items.claimantSpoke',
                'evidence' => fn ($query) => $query->orderBy('id'),
                'dependencies' => fn ($query) => $query->orderBy('work_tasks.id'),
                'children' => fn ($query) => $query->orderBy('id'),
                'children.items.claimantSpoke',
                'children.evidence' => fn ($query) => $query->orderBy('id'),
                'children.dependencies' => fn ($query) => $query->orderBy('work_tasks.id'),
            ])->get();
        $rootTaskIds = $tasks->modelKeys();
        $openEscalations = WorkEscalation::query()->whereHas('workTask', fn ($query) => $query
            ->whereIn('id', $rootTaskIds)->orWhereIn('parent_task_id', $rootTaskIds))
            ->whereNull('answered_at')->orderBy('id')->get()->groupBy('work_task_id');

        /** @var array<string, list<array<string, mixed>>> $boardColumns */
        $boardColumns = ['queued' => [], 'running' => [], 'finished' => []];
        foreach ($tasks as $task) {
            $treeTasks = collect([$task])->concat($task->children);
            $items = $treeTasks->flatMap(fn (WorkTask $treeTask) => $treeTask->items);
            $runningItems = $items->filter(
                fn (WorkItem $item) => in_array($item->state->value, ['claimed', 'delivered', 'routed'], true),
            );
            $column = $items->isNotEmpty() && $items->every(fn (WorkItem $item) => $item->state->isTerminal())
                ? 'finished'
                : ($runningItems->isNotEmpty() ? 'running' : 'queued');
            $stateOfPlay = $items->whereNotNull('last_progress')->sortByDesc('updated_at')->first();

            $boardColumns[$column][] = [
                'task' => $task,
                'children' => $task->children->map(fn (WorkTask $child) => [
                    'task' => $child,
                    'prUrl' => $this->safePullRequestUrl($child->pr),
                ]),
                'runningItems' => $runningItems->map(function (WorkItem $item): array {
                    $machine = $this->machineDisplay($item->claimantSpoke);

                    return ['item' => $item, ...$machine];
                }),
                'escalations' => $treeTasks->flatMap(
                    fn (WorkTask $treeTask) => $openEscalations->get($treeTask->id, collect()),
                ),
                'evidence' => $treeTasks->flatMap(fn (WorkTask $treeTask) => $treeTask->evidence),
                'dependencies' => $treeTasks->flatMap(fn (WorkTask $treeTask) => $treeTask->dependencies)
                    ->unique('id')->values(),
                'stateOfPlay' => $stateOfPlay?->last_progress,
            ];
        }

        return view('livewire.work.index', [
            'team' => Team::default(),
            'canManagePoolWork' => RolePolicy::canManageMembers(request()->user()->role),
            'boardColumns' => $boardColumns,
            'escalations' => WorkEscalation::query()
                ->where('addressed_actor', $actor)->whereNull('answered_at')
                ->whereHas('workTask', fn ($query) => $query->whereNull('cancelled_at'))
                ->whereHas('workItem', fn ($query) => $query->where('role', WorkItem::ROLE_HUMAN)
                    ->where('addressed_actor', $actor)->where('state', 'queued'))
                ->orderBy('id')->limit(25)->get(),
        ]);
    }

    /** @param array{repo: string, number: int, url: string}|null $pullRequest */
    private function safePullRequestUrl(?array $pullRequest): ?string
    {
        if ($pullRequest === null || trim($pullRequest['repo']) === '' || $pullRequest['number'] < 1) {
            return null;
        }

        $url = $pullRequest['url'];
        $parts = filter_var($url, FILTER_VALIDATE_URL) !== false ? parse_url($url) : false;

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'], $parts['path'])
            || strcasecmp($parts['scheme'], 'https') !== 0
            || strcasecmp($parts['host'], 'github.com') !== 0
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('port', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || $parts['path'] !== '/'.$pullRequest['repo'].'/pull/'.$pullRequest['number']) {
            return null;
        }

        return $url;
    }

    /** @return array{machine: string, machineSource: string} */
    private function machineDisplay(?Spoke $spoke): array
    {
        foreach (['device_name', 'name'] as $source) {
            $label = $spoke?->{$source};
            if (is_string($label) && trim($label) !== '') {
                return ['machine' => $label, 'machineSource' => $source];
            }
        }

        return ['machine' => __('Unavailable'), 'machineSource' => 'unavailable'];
    }

    public function answerEscalation(string $escalationId, WorkOrchestrationService $orchestration): void
    {
        $this->guardAccess();
        abort_unless(Str::isUuid($escalationId), 404);
        $actor = (string) request()->user()->getKey();
        $validated = $this->validate([
            'answers.'.$escalationId => ['required', 'string', 'min:1', 'max:10000'],
        ]);
        $escalation = WorkEscalation::query()->whereKey($escalationId)
            ->where('addressed_actor', $actor)->first();
        abort_unless($escalation instanceof WorkEscalation, 404);

        try {
            $orchestration->answer($escalation, $actor, $validated['answers'][$escalationId], now()->toImmutable());
        } catch (\DomainException) {
            abort(404);
        }
        unset($this->answers[$escalationId]);
    }

    public function setPoolWork(mixed $poolWork, TeamWorkPolicy $policy): void
    {
        $this->guardAccess();
        // Livewire coerces scalars into a bool parameter, so "false" would turn pooling on.
        if (! is_bool($poolWork)) {
            $this->addError('poolWork', __('Pooled work can only be turned on or off.'));

            return;
        }
        try {
            $policy->setPoolWork(Team::default(), request()->user(), $poolWork, TeamWorkPolicy::SURFACE_WEB);
        } catch (AuthorizationException $exception) {
            $this->addError('poolWork', $exception->getMessage());
        }
    }

    private function guardAccess(): void
    {
        $user = request()->user();
        abort_unless($user instanceof User && $user->status === 'active'
            && ! OffboardedSubject::userIsOffboarded((string) $user->getKey())
            && resolve(IdentityContext::class)->canUseProduct(), 403);
    }
}
