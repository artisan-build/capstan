<?php

namespace App\Livewire\Postmaster;

use App\Enums\SpokeLiveness;
use App\Enums\SpokeMapStatus;
use App\Features\Postmaster;
use App\Machine\InitialMachineWorkProfile;
use App\Machine\MachineWorkProfiles;
use App\Models\MachineTeamWorkSetting;
use App\Models\Spoke;
use App\Models\Team;
use App\Postmaster\PendingInstallLinks;
use App\Postmaster\RetireSpoke;
use ArtisanBuild\BuiltForCloud\Contracts\IdentityContext;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Laravel\Pennant\Feature;
use Livewire\Component;

class SpokeMap extends Component
{
    public ?int $renamingSpokeId = null;

    public string $spokeName = '';

    public ?int $editingWorkSpokeId = null;

    public bool $workEnabled = true;

    public string $workRoles = '';

    public string $workCapabilities = '';

    public string $workRoleProviders = '{}';

    public int $workConcurrencyCeiling = 1;

    public function mount(): void
    {
        $this->guardFeature();
    }

    public function render(): View
    {
        $this->guardFeature();

        return view('livewire.postmaster.spoke-map', [
            'spokes' => collect($this->spokes()),
        ]);
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

    public function beginEditingWorkProfile(int $spokeId, InitialMachineWorkProfile $defaults): void
    {
        $this->guardFeature();
        $spoke = $this->manageableWorkSpoke($spokeId);
        $setting = $spoke->workSettings()->where('team_id', Team::default()->id)->first();
        $profile = $setting === null ? $defaults->default() : [
            'enabled' => $setting->enabled,
            'roles' => $setting->roles,
            'capabilities' => $setting->capabilities,
            'role_providers' => $setting->role_providers,
            'concurrency_ceiling' => $setting->concurrency_ceiling,
        ];

        $this->editingWorkSpokeId = $spoke->id;
        $this->workEnabled = $profile['enabled'];
        $this->workRoles = implode(', ', $profile['roles']);
        $this->workCapabilities = implode(', ', $profile['capabilities']);
        $this->workRoleProviders = json_encode($profile['role_providers'], JSON_THROW_ON_ERROR);
        $this->workConcurrencyCeiling = $profile['concurrency_ceiling'];
    }

    public function cancelEditingWorkProfile(): void
    {
        $this->reset('editingWorkSpokeId', 'workRoles', 'workCapabilities', 'workRoleProviders');
        $this->workEnabled = true;
        $this->workConcurrencyCeiling = 1;
        $this->resetValidation('workProfile');
    }

    public function saveWorkProfile(int $spokeId, MachineWorkProfiles $profiles): void
    {
        $this->guardFeature();
        $spoke = $this->manageableWorkSpoke($spokeId);
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
            $profiles->put($spoke, Team::default(), $actor, [
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

    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     device_name: string|null,
     *     owner_name: string,
     *     can_rename: bool,
     *     can_manage_work: bool,
     *     work_setting: MachineTeamWorkSetting|null,
     *     last_polled_at: CarbonImmutable|null,
     *     inboxes_count: int,
     *     probe_status: SpokeLiveness,
     *     status: SpokeMapStatus
     * }>
     */
    private function spokes(): array
    {
        $team = Team::default();
        /** @var Collection<int, Spoke> $spokes */
        $spokes = Spoke::query()->whereNull('retired_at')
            ->with(['workSettings' => fn ($query) => $query->where('team_id', $team->id)])
            ->withCount('inboxes')->orderBy('id')->get();
        $ownerNames = $this->ownerNames($spokes);
        $baseNames = $spokes->mapWithKeys(fn (Spoke $spoke): array => [
            $spoke->id => $this->baseDisplayName($spoke),
        ]);
        $duplicateNames = $spokes
            ->groupBy(fn (Spoke $spoke): string => $spoke->actor_id."\0".$baseNames[$spoke->id])
            ->map->count()
            ->all();
        $user = request()->user();
        $actorId = $user instanceof User ? (string) $user->getKey() : null;
        $canManageAny = $user instanceof User && RolePolicy::canManageMembers($user->role);

        $staleAfter = max(60, (int) config('capstan.postmaster.map.stale_after_seconds', 300));
        $staleBefore = now()->subSeconds($staleAfter);

        return array_values($spokes
            ->map(fn (Spoke $spoke): array => [
                'id' => $spoke->id,
                'name' => $this->displayName($spoke, $baseNames, $duplicateNames),
                'device_name' => $this->deviceName($spoke),
                'owner_name' => $ownerNames[$spoke->actor_id] ?? __('Unknown user'),
                'can_rename' => $actorId !== null && hash_equals($actorId, $spoke->actor_id),
                'can_manage_work' => $actorId !== null && ($canManageAny || hash_equals($actorId, $spoke->actor_id)),
                'work_setting' => $spoke->workSettings->first(),
                'last_polled_at' => $spoke->last_polled_at,
                'inboxes_count' => (int) $spoke->inboxes_count,
                'probe_status' => $spoke->probe_status,
                'status' => $this->mapStatus($spoke, $staleBefore),
            ])
            ->all());
    }

    private function mapStatus(Spoke $spoke, CarbonInterface $staleBefore): SpokeMapStatus
    {
        if ($spoke->last_polled_at === null || $spoke->last_polled_at->lt($staleBefore)) {
            return SpokeMapStatus::Red;
        }

        return match ($spoke->probe_status) {
            SpokeLiveness::Green => SpokeMapStatus::Green,
            SpokeLiveness::Red => SpokeMapStatus::Red,
            SpokeLiveness::Unknown => SpokeMapStatus::Pending,
        };
    }

    /**
     * @param  Collection<int, string>  $baseNames
     * @param  array<int|string, int>  $duplicateNames
     */
    private function displayName(Spoke $spoke, Collection $baseNames, array $duplicateNames): string
    {
        $name = $baseNames[$spoke->id];

        return $duplicateNames[$spoke->actor_id."\0".$name] > 1
            ? $name.' (#'.$spoke->id.')'
            : $name;
    }

    private function baseDisplayName(Spoke $spoke): string
    {
        foreach ([$spoke->name, $this->deviceName($spoke)] as $name) {
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return __('Spoke #:id', ['id' => $spoke->id]);
    }

    private function deviceName(Spoke $spoke): ?string
    {
        $deviceName = $spoke->getAttribute('device_name');

        return is_string($deviceName) ? $deviceName : null;
    }

    /**
     * @param  Collection<int, Spoke>  $spokes
     * @return array<string, string>
     */
    private function ownerNames(Collection $spokes): array
    {
        $actorIds = $spokes->pluck('actor_id')
            ->unique()
            ->filter(fn (mixed $actorId): bool => is_string($actorId)
                && filter_var($actorId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false)
            ->values()
            ->all();

        return User::query()
            ->whereIn('id', $actorIds)
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(function (User $user): array {
                $name = trim($user->name);
                $email = trim($user->email);

                return [(string) $user->getKey() => $name !== '' ? $name : ($email !== '' ? $email : __('Unknown user'))];
            })
            ->all();
    }

    private function ownedSpoke(int $spokeId): Spoke
    {
        $user = request()->user();
        abort_unless($user instanceof User, 401);

        $spoke = Spoke::query()->whereNull('retired_at')->findOrFail($spokeId);
        abort_unless(hash_equals((string) $user->getKey(), $spoke->actor_id), 403);

        return $spoke;
    }

    private function manageableWorkSpoke(int $spokeId): Spoke
    {
        $user = request()->user();
        abort_unless($user instanceof User, 401);
        $spoke = Spoke::query()->whereNull('retired_at')->findOrFail($spokeId);
        abort_unless(hash_equals((string) $user->getKey(), $spoke->actor_id) || RolePolicy::canManageMembers($user->role), 403);

        return $spoke;
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
