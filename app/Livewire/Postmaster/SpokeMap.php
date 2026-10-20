<?php

namespace App\Livewire\Postmaster;

use App\Features\Postmaster;
use App\Machine\InitialMachineWorkProfile;
use App\Machine\MachineBudgetProjection;
use App\Machine\MachineWorkProfiles;
use App\Machine\TeamMemberships;
use App\Models\Spoke;
use App\Models\Team;
use App\Postmaster\PendingInstallLinks;
use App\Postmaster\RetireSpoke;
use ArtisanBuild\BuiltForCloud\Contracts\IdentityContext;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Laravel\Pennant\Feature;
use Livewire\Component;

class SpokeMap extends Component
{
    public ?int $renamingSpokeId = null;

    public string $spokeName = '';

    public ?int $editingWorkSpokeId = null;

    public ?int $editingWorkTeamId = null;

    public ?int $selectedTeamId = null;

    /** @var list<array{id: int, name: string}> */
    public array $workTeams = [];

    public bool $workEnabled = true;

    public string $workRoles = '';

    public string $workCapabilities = '';

    public string $workRoleProviders = '{}';

    public int $workConcurrencyCeiling = 1;

    public function mount(TeamMemberships $memberships): void
    {
        $this->guardFeature();
        $user = request()->user();
        abort_unless($user instanceof User, 401);
        $teams = $memberships->teamsFor($user)->orderByDesc('is_default')->orderBy('name')->orderBy('id')->get(['id', 'name']);
        foreach ($teams as $team) {
            $this->workTeams[] = ['id' => $team->id, 'name' => $team->name];
        }
        $this->selectedTeamId = $this->workTeams[0]['id'] ?? null;
    }

    public function render(MachineBudgetProjection $budgets): View
    {
        $this->guardFeature();

        $selectedTeam = collect($this->workTeams)->firstWhere('id', $this->selectedTeamId);
        abort_unless(is_array($selectedTeam), 404);
        $user = request()->user();
        abort_unless($user instanceof User, 401);

        $machineBudgets = $budgets->forTeam($user, $selectedTeam['id'], CarbonImmutable::now());

        return view('livewire.postmaster.spoke-map', [
            'teams' => $this->workTeams,
            'selectedTeam' => $selectedTeam,
            'spokes' => collect($machineBudgets['machines']),
            'machineBudgets' => $machineBudgets,
        ]);
    }

    public function updatedSelectedTeamId(): void
    {
        $this->cancelEditingWorkProfile();
    }

    public function generateOnboardingSnippet(PendingInstallLinks $links, IdentityContext $identity): void
    {
        $this->guardFeature();
        abort_unless($identity->canUseProduct(), 403);

        $user = request()->user();
        abort_unless($user instanceof User, 401);

        $key = 'postmaster-onboarding:'.(request()->ip() ?: 'unknown');
        abort_if(RateLimiter::tooManyAttempts($key, 15), 429);
        RateLimiter::hit($key, 60);

        $install = $links->issue(request(), (string) $user->getKey());

        // Dispatching keeps the capability out of Livewire's persisted snapshot.
        $this->dispatch('postmaster-installer-generated',
            command: $install->command,
            script: $install->script,
            expiresAt: $install->expiresAt->getTimestamp(),
        );
    }

    public function beginRenaming(int $spokeId): void
    {
        $this->guardFeature();
        $spoke = $this->ownedSpoke($spokeId);

        $this->renamingSpokeId = $spoke->id;
        $this->spokeName = $spoke->name ?? '';
    }

    public function cancelRenaming(): void
    {
        $this->guardFeature();
        $this->reset('renamingSpokeId', 'spokeName');
    }

    public function renameSpoke(int $spokeId): void
    {
        $this->guardFeature();
        $spoke = $this->ownedSpoke($spokeId);
        $validated = $this->validate([
            'spokeName' => ['string', 'max:64'],
        ]);
        $name = trim($validated['spokeName']);

        $spoke->forceFill(['name' => $name === '' ? null : $name])->save();
        $this->reset('renamingSpokeId', 'spokeName');
    }

    public function retireSpoke(int $spokeId, RetireSpoke $retire): void
    {
        $this->guardFeature();
        $spoke = $this->ownedSpoke($spokeId);

        try {
            $retire->handle($spoke->id, $spoke->actor_id, CarbonImmutable::now());
        } catch (DomainException $exception) {
            $this->addError('retireSpoke', $exception->getMessage());
        }
    }

    public function beginEditingWorkProfile(
        int $spokeId,
        InitialMachineWorkProfile $defaults,
        MachineWorkProfiles $profiles,
    ): void {
        $this->guardFeature();
        $team = $this->selectedTeam();
        $spoke = Spoke::query()->whereNull('retired_at')->findOrFail($spokeId);
        $actor = request()->user();
        abort_unless($actor instanceof User, 401);
        $profiles->authorize($spoke, $team, $actor);
        $setting = $spoke->workSettings()->where('team_id', $team->id)->first();
        $profile = $setting === null ? $defaults->default() : [
            'enabled' => $setting->enabled,
            'roles' => $setting->roles,
            'capabilities' => $setting->capabilities,
            'role_providers' => $setting->role_providers,
            'concurrency_ceiling' => $setting->concurrency_ceiling,
        ];

        $this->editingWorkSpokeId = $spoke->id;
        $this->editingWorkTeamId = $team->id;
        $this->workEnabled = $profile['enabled'];
        $this->workRoles = implode(', ', $profile['roles']);
        $this->workCapabilities = implode(', ', $profile['capabilities']);
        $this->workRoleProviders = json_encode($profile['role_providers'], JSON_THROW_ON_ERROR);
        $this->workConcurrencyCeiling = $profile['concurrency_ceiling'];
    }

    public function cancelEditingWorkProfile(): void
    {
        $this->reset('editingWorkSpokeId', 'editingWorkTeamId', 'workRoles', 'workCapabilities', 'workRoleProviders');
        $this->workEnabled = true;
        $this->workConcurrencyCeiling = 1;
        $this->resetValidation('workProfile');
    }

    public function saveWorkProfile(int $spokeId, MachineWorkProfiles $profiles): void
    {
        $this->guardFeature();
        $team = $this->selectedTeam();
        abort_unless($this->editingWorkSpokeId === $spokeId && $this->editingWorkTeamId === $team->id, 409);
        $spoke = Spoke::query()->whereNull('retired_at')->findOrFail($spokeId);
        $validated = $this->validate([
            'workEnabled' => ['required', 'boolean'],
            'workRoles' => ['present', 'string', 'max:1624'],
            'workCapabilities' => ['present', 'string', 'max:1624'],
            'workRoleProviders' => ['required', 'string', 'max:4096'],
            'workConcurrencyCeiling' => ['required', 'integer', 'min:1', 'max:25'],
        ]);

        try {
            $providers = json_decode($validated['workRoleProviders'], true, 4, JSON_THROW_ON_ERROR);
            if (! is_array($providers)) {
                throw new InvalidArgumentException('Role providers must be a JSON object.');
            }
            $actor = request()->user();
            abort_unless($actor instanceof User, 401);
            /** @var array<string, string> $providers */
            $profiles->put($spoke, $team, $actor, [
                'enabled' => $validated['workEnabled'],
                'roles' => $this->commaList($validated['workRoles']),
                'capabilities' => $this->commaList($validated['workCapabilities']),
                'role_providers' => $providers,
                'concurrency_ceiling' => $validated['workConcurrencyCeiling'],
            ], CarbonImmutable::now());
        } catch (AuthorizationException|InvalidArgumentException|\JsonException $exception) {
            $this->addError('workProfile', $exception->getMessage());

            return;
        }

        $this->cancelEditingWorkProfile();
    }

    private function ownedSpoke(int $spokeId): Spoke
    {
        $user = request()->user();
        abort_unless($user instanceof User, 401);

        $spoke = Spoke::query()->whereNull('retired_at')->findOrFail($spokeId);
        abort_unless(hash_equals((string) $user->getKey(), $spoke->actor_id), 403);

        return $spoke;
    }

    private function selectedTeam(): Team
    {
        $user = request()->user();
        abort_unless($user instanceof User, 401);
        abort_unless($this->selectedTeamId !== null && resolve(TeamMemberships::class)->belongsTo($user, $this->selectedTeamId), 404);

        return Team::query()->findOrFail($this->selectedTeamId);
    }

    /** @return list<string> */
    private function commaList(string $value): array
    {
        $entries = array_values(array_filter(array_map(trim(...), explode(',', $value)), fn (string $entry): bool => $entry !== ''));

        return array_values(array_unique($entries));
    }

    private function guardFeature(): void
    {
        abort_unless(Feature::active(Postmaster::class), 404);
    }
}
