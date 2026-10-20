<?php

namespace App\Livewire\Work;

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

        return view('livewire.work.index', [
            'team' => Team::default(),
            'canManagePoolWork' => RolePolicy::canManageMembers(request()->user()->role),
            'tasks' => WorkTask::query()->orderByDesc('id')->limit(25)
                ->with([
                    'items' => fn ($query) => $query->orderBy('id')->limit(25),
                    'items.milestones' => fn ($query) => $query->orderByDesc('id')->limit(1),
                    'briefRevisions' => fn ($query) => $query->reorder()->orderByDesc('sequence')->limit(1),
                    'steers' => fn ($query) => $query->orderByDesc('id')->limit(10),
                ])->withCount('items')->get(),
            'escalations' => WorkEscalation::query()
                ->where('addressed_actor', $actor)->whereNull('answered_at')
                ->whereHas('workTask', fn ($query) => $query->whereNull('cancelled_at'))
                ->whereHas('workItem', fn ($query) => $query->where('role', WorkItem::ROLE_HUMAN)
                    ->where('addressed_actor', $actor)->where('state', 'queued'))
                ->orderBy('id')->limit(25)->get(),
        ]);
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
