<?php

namespace App\Livewire\Postmaster;

use App\Enums\OrgRole;
use App\Enums\SpokeLiveness;
use App\Enums\SpokeMapStatus;
use App\Features\Postmaster;
use App\Models\Spoke;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Laravel\Pennant\Feature;
use Livewire\Component;

class SpokeMap extends Component
{
    public function mount(): void
    {
        if (! Feature::active(Postmaster::class)) {
            abort(404);
        }
    }

    public function render(): View
    {
        return view('livewire.postmaster.spoke-map', [
            'spokes' => collect($this->spokes()),
        ]);
    }

    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     owner_name: string,
     *     last_polled_at: CarbonImmutable|null,
     *     inboxes_count: int,
     *     probe_status: SpokeLiveness,
     *     status: SpokeMapStatus
     * }>
     */
    private function spokes(): array
    {
        /** @var User $user */
        $user = Auth::user();

        $query = Spoke::query()
            ->with('user:id,name')
            ->withCount('inboxes');

        if (! in_array($user->org_role, [OrgRole::Owner, OrgRole::Admin], true)) {
            $query->where('user_id', $user->id);
        }

        $staleBefore = now()->subSeconds((int) config('capstan.postmaster.map.stale_after_seconds'));

        return array_values($query->get()
            ->sort(function (Spoke $first, Spoke $second) use ($staleBefore): int {
                $statusOrder = $this->statusOrder($this->mapStatus($first, $staleBefore))
                    <=> $this->statusOrder($this->mapStatus($second, $staleBefore));

                if ($statusOrder !== 0) {
                    return $statusOrder;
                }

                $nameOrder = strnatcasecmp($this->displayName($first), $this->displayName($second));

                return $nameOrder !== 0 ? $nameOrder : $first->id <=> $second->id;
            })
            ->values()
            ->map(fn (Spoke $spoke): array => [
                'id' => $spoke->id,
                'name' => $this->displayName($spoke),
                'owner_name' => $spoke->user->name,
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

    private function displayName(Spoke $spoke): string
    {
        return $spoke->name ?? __('Spoke #:id', ['id' => $spoke->id]);
    }

    private function statusOrder(SpokeMapStatus $status): int
    {
        return $status === SpokeMapStatus::Red ? 0 : 1;
    }
}
