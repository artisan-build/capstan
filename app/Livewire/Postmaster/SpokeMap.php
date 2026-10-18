<?php

namespace App\Livewire\Postmaster;

use App\Enums\SpokeLiveness;
use App\Enums\SpokeMapStatus;
use App\Features\Postmaster;
use App\Models\Spoke;
use App\Postmaster\PendingInstallLinks;
use ArtisanBuild\BuiltForCloud\Contracts\IdentityContext;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Pennant\Feature;
use Livewire\Component;

class SpokeMap extends Component
{
    public ?int $renamingSpokeId = null;

    public string $spokeName = '';

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

    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     device_name: string|null,
     *     owner_name: string,
     *     can_rename: bool,
     *     last_polled_at: CarbonImmutable|null,
     *     inboxes_count: int,
     *     probe_status: SpokeLiveness,
     *     status: SpokeMapStatus
     * }>
     */
    private function spokes(): array
    {
        /** @var Collection<int, Spoke> $spokes */
        $spokes = Spoke::query()->withCount('inboxes')->get();
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

        $staleAfter = max(60, (int) config('capstan.postmaster.map.stale_after_seconds', 300));
        $staleBefore = now()->subSeconds($staleAfter);

        return array_values($spokes
            ->sort(function (Spoke $first, Spoke $second) use ($baseNames, $duplicateNames, $staleBefore): int {
                $statusOrder = $this->statusOrder($this->mapStatus($first, $staleBefore))
                    <=> $this->statusOrder($this->mapStatus($second, $staleBefore));

                if ($statusOrder !== 0) {
                    return $statusOrder;
                }

                $nameOrder = strnatcasecmp(
                    $this->displayName($first, $baseNames, $duplicateNames),
                    $this->displayName($second, $baseNames, $duplicateNames),
                );

                return $nameOrder !== 0 ? $nameOrder : $first->id <=> $second->id;
            })
            ->values()
            ->map(fn (Spoke $spoke): array => [
                'id' => $spoke->id,
                'name' => $this->displayName($spoke, $baseNames, $duplicateNames),
                'device_name' => $this->deviceName($spoke),
                'owner_name' => $ownerNames[$spoke->actor_id] ?? __('Unknown user'),
                'can_rename' => $actorId !== null && hash_equals($actorId, $spoke->actor_id),
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

        $spoke = Spoke::query()->findOrFail($spokeId);
        abort_unless(hash_equals((string) $user->getKey(), $spoke->actor_id), 403);

        return $spoke;
    }

    private function statusOrder(SpokeMapStatus $status): int
    {
        return $status === SpokeMapStatus::Red ? 0 : 1;
    }

    private function guardFeature(): void
    {
        abort_unless(Feature::active(Postmaster::class), 404);
    }
}
