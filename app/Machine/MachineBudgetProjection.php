<?php

namespace App\Machine;

use App\Models\MachineTeamWorkSetting;
use App\Models\MachineUsageProvider;
use App\Models\MachineUsageReport;
use App\Models\MachineUsageWindow;
use App\Models\Spoke;
use App\Models\Team;
use App\Postmaster\MachineLifecycle;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final readonly class MachineBudgetProjection
{
    public const int MAX_MACHINES = 100;

    public function __construct(
        private MachineUsageFreshness $freshness,
        private MachineLifecycle $lifecycle,
        private TeamMemberships $memberships,
        private MachineWorkProfiles $workProfiles,
    ) {}

    /**
     * @return array{
     *     team: array{id: int, name: string},
     *     routing_aid_notice: string,
     *     machines: list<array<string, mixed>>,
     *     people: list<array<string, mixed>>,
     *     bounds: array{machines: int, truncated: bool},
     *     generated_at: string
     * }
     */
    public function forTeam(User $viewer, int $teamId, CarbonImmutable $now): array
    {
        $this->authorize($viewer);
        if (! $this->memberships->belongsTo($viewer, $teamId)) {
            throw new AuthorizationException('The selected team is unavailable.');
        }
        $team = Team::query()->findOrFail($teamId, ['id', 'name']);
        $selection = $this->workProfiles->projectionCandidates($team, $viewer, true, self::MAX_MACHINES);
        /** @var EloquentCollection<int, Spoke> $machines */
        $machines = new EloquentCollection($selection['machines']->all());
        $machines->load('usageReport.providers.windows')->loadCount('inboxes');
        $owners = $selection['owners'];
        $baseNames = $machines->mapWithKeys(fn (Spoke $machine): array => [$machine->id => $this->baseDisplayName($machine)]);
        $duplicateNames = $machines->groupBy(
            fn (Spoke $machine): string => $machine->actor_id."\0".$baseNames[$machine->id],
        )->map->count()->all();
        $items = array_values($machines->map(fn (Spoke $machine): array => $this->machine(
            $machine,
            $this->ownerName($owners->get($machine->actor_id)),
            $duplicateNames[$machine->actor_id."\0".$baseNames[$machine->id]] > 1
                ? $baseNames[$machine->id].' (#'.$machine->id.')'
                : $baseNames[$machine->id],
            (string) $viewer->getKey(),
            $selection['permissions'][$machine->id] ?? false,
            $now,
        ))->values()->all());
        $people = $this->people($items);
        $items = array_map(function (array $item): array {
            unset($item['_owner_key']);

            return $item;
        }, $items);

        return [
            'team' => ['id' => $team->id, 'name' => $team->name],
            'routing_aid_notice' => __('Routing aid only. Percentages are per machine and are never combined into billing totals.'),
            'machines' => $items,
            'people' => $people,
            'bounds' => ['machines' => self::MAX_MACHINES, 'truncated' => $selection['truncated']],
            'generated_at' => $now->toJSON(),
        ];
    }

    private function authorize(User $viewer): void
    {
        if (! $this->workProfiles->isAdmitted($viewer)) {
            throw new AuthorizationException('The selected team is unavailable.');
        }
    }

    private function ownerName(?User $owner): string
    {
        if (! $owner instanceof User) {
            return __('Former member');
        }

        return trim($owner->name) !== '' ? trim($owner->name) : __('Member');
    }

    /** @return array<string, mixed> */
    private function machine(Spoke $machine, string $ownerName, string $displayName, string $viewerId, bool $canManageWork, CarbonImmutable $now): array
    {
        $setting = $machine->workSettings->first();
        $report = $machine->usageReport;
        $providers = $this->providers(
            $machine,
            $report,
            $setting instanceof MachineTeamWorkSetting ? $setting->role_providers : [],
            $now,
        );
        $isOwner = hash_equals($viewerId, $machine->actor_id) && $machine->orphaned_at === null;

        return [
            'id' => $machine->id,
            'machine_id' => $machine->id,
            'name' => $displayName,
            'device_name' => $this->deviceName($machine),
            'owner_name' => $ownerName,
            '_owner_key' => hash('sha256', 'capstan-machine-owner\0'.$machine->actor_id),
            'lifecycle_state' => $this->lifecycle->state($machine, $now)->value,
            'status' => $this->mapStatus($machine, $now),
            'probe_state' => $machine->probe_status->value,
            'probe_status' => $machine->probe_status->value,
            'last_polled_at' => $machine->last_polled_at?->toJSON(),
            'inboxes_count' => (int) $machine->inboxes_count,
            'work_enabled' => $setting instanceof MachineTeamWorkSetting && $setting->enabled,
            'can_rename' => $isOwner,
            'can_retire' => $isOwner,
            'can_manage_work' => $canManageWork,
            'reactivation_required' => $machine->orphaned_at !== null,
            'usage' => [
                'availability' => $report instanceof MachineUsageReport ? $report->availability : 'unknown',
                'source' => $report instanceof MachineUsageReport ? $report->source : null,
                'collected_at' => $report?->collected_at?->toJSON(),
                'freshness' => $this->reportFreshness($report, $providers),
                'providers' => $providers,
            ],
        ];
    }

    /**
     * @param  array<string, string>  $roleProviders
     * @return list<array<string, mixed>>
     */
    private function providers(Spoke $machine, ?MachineUsageReport $report, array $roleProviders, CarbonImmutable $now): array
    {
        /** @var Collection<string, MachineUsageProvider> $reported */
        $reported = $report?->providers->keyBy('provider') ?? collect();
        $families = array_values(array_unique(array_merge(
            $reported->keys()->map(fn (mixed $provider): string => (string) $provider)->all(),
            array_values($roleProviders),
        )));
        sort($families, SORT_STRING);

        return array_map(function (string $family) use ($machine, $report, $reported, $now): array {
            $provider = $reported->get($family);
            if (! $report instanceof MachineUsageReport || ! $provider instanceof MachineUsageProvider) {
                return $this->unknownProvider($machine, $family);
            }

            $fresh = $this->freshness->providerIsFresh($report, $provider, $now);
            $windows = $provider->windows->sortBy(fn (MachineUsageWindow $window): string => ($window->window_seconds === null ? '9999999999' : str_pad((string) $window->window_seconds, 10, '0', STR_PAD_LEFT))."\0".$window->slot
            )->values();
            $remaining = $windows->isEmpty() ? null : (float) $windows->min(
                fn (MachineUsageWindow $window): float => (float) $window->remaining_percent,
            );
            $correspondingResets = $remaining === null ? collect() : $windows
                ->filter(fn (MachineUsageWindow $window): bool => (float) $window->remaining_percent === $remaining)
                ->pluck('resets_at')->filter()->map->getTimestamp();

            return [
                'machine_id' => $machine->id,
                'provider' => $provider->provider,
                'plan' => $provider->plan,
                'status' => $provider->status,
                'freshness' => $fresh ? ($windows->isEmpty() ? 'unknown' : 'fresh') : 'stale',
                'remaining_percent' => $remaining,
                'resets_at' => $correspondingResets->isEmpty() ? null : CarbonImmutable::createFromTimestampUTC((int) $correspondingResets->min())->toJSON(),
                'windows' => array_values($windows->map(fn (MachineUsageWindow $window): array => [
                    'slot' => $window->slot,
                    'kind' => $window->kind,
                    'window_seconds' => $window->window_seconds,
                    'remaining_percent' => (float) $window->remaining_percent,
                    'resets_at' => $window->resets_at?->toJSON(),
                ])->all()),
            ];
        }, $families);
    }

    /** @return array<string, mixed> */
    private function unknownProvider(Spoke $machine, string $family): array
    {
        return [
            'machine_id' => $machine->id,
            'provider' => $family,
            'plan' => null,
            'status' => 'unknown',
            'freshness' => 'unknown',
            'remaining_percent' => null,
            'resets_at' => null,
            'windows' => [],
        ];
    }

    /** @param list<array<string, mixed>> $providers */
    private function reportFreshness(?MachineUsageReport $report, array $providers): string
    {
        if (! $report instanceof MachineUsageReport || $report->availability !== 'available' || $providers === []) {
            return 'unknown';
        }

        return collect($providers)->contains(fn (array $provider): bool => $provider['freshness'] !== 'fresh') ? 'stale' : 'fresh';
    }

    /**
     * @param  list<array<string, mixed>>  $machines
     * @return list<array<string, mixed>>
     */
    private function people(array $machines): array
    {
        return array_values(collect($machines)->groupBy('_owner_key')
            ->map(function (Collection $owned): array {
                $ownerName = (string) $owned->first()['owner_name'];
                $providers = array_values($owned->flatMap(fn (array $machine): array => array_map(
                    fn (array $provider): array => [...$provider, 'machine_name' => $machine['name']],
                    $machine['usage']['providers'],
                ))->groupBy('provider')->sortKeys()->map(function (Collection $values, string $provider): array {
                    $values = $values->sortBy('machine_id')->values();
                    $fresh = $values->where('freshness', 'fresh');
                    $best = $fresh->max('remaining_percent');
                    $corresponding = $best === null ? collect() : $fresh->filter(
                        fn (array $value): bool => $value['remaining_percent'] === $best,
                    );

                    return [
                        'provider' => $provider,
                        'best_fresh_remaining_percent' => $best,
                        'earliest_corresponding_reset_at' => $corresponding->pluck('resets_at')->filter()->sort()->first(),
                        'stale_machines_count' => $values->where('freshness', 'stale')->count(),
                        'unknown_machines_count' => $values->where('freshness', 'unknown')->count(),
                        'machines' => $values->map(fn (array $value): array => [
                            'machine_id' => $value['machine_id'],
                            'machine_name' => $value['machine_name'],
                            'plan' => $value['plan'],
                            'freshness' => $value['freshness'],
                            'remaining_percent' => $value['remaining_percent'],
                            'resets_at' => $value['resets_at'],
                        ])->all(),
                    ];
                })->values()->all());

                return ['owner_name' => $ownerName, 'providers' => $providers];
            })->sortBy(fn (array $person): string => mb_strtolower($person['owner_name']))->values()->all());
    }

    private function baseDisplayName(Spoke $machine): string
    {
        foreach ([$machine->name, $this->deviceName($machine)] as $name) {
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return __('Spoke #:id', ['id' => $machine->id]);
    }

    private function deviceName(Spoke $machine): ?string
    {
        $name = $machine->getAttribute('device_name');

        return is_string($name) ? $name : null;
    }

    private function mapStatus(Spoke $machine, CarbonImmutable $now): string
    {
        $staleAfter = max(60, (int) config('capstan.postmaster.map.stale_after_seconds', 300));
        if ($machine->last_polled_at === null || $machine->last_polled_at->lt($now->subSeconds($staleAfter))) {
            return 'red';
        }

        return match ($machine->probe_status->value) {
            'green' => 'green',
            'red' => 'red',
            default => 'pending',
        };
    }
}
