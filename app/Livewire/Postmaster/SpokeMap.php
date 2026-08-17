<?php

namespace App\Livewire\Postmaster;

use App\Enums\OrgRole;
use App\Enums\SpokeLiveness;
use App\Enums\SpokeMapStatus;
use App\Features\Postmaster;
use App\Models\DeviceCode;
use App\Models\Spoke;
use App\Models\User;
use App\Postmaster\OnboardingSnippet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Pennant\Feature;
use Livewire\Component;

class SpokeMap extends Component
{
    public ?string $onboardingSnippet = null;

    public ?int $onboardingExpiresAt = null;

    public function mount(): void
    {
        $this->guardFeature();
    }

    public function generateOnboardingSnippet(OnboardingSnippet $onboarding): void
    {
        $this->guardFeature();
        abort_unless($this->canOnboard(), 403);
        abort_unless(RateLimiter::attempt(
            $this->onboardingRateLimitKey(),
            15,
            static fn (): bool => true,
            60,
        ), 429);

        $this->onboardingSnippet = $onboarding->generate();
        $this->onboardingExpiresAt = now()->addSeconds(DeviceCode::LIFETIME_SECONDS)->getTimestamp();
    }

    public function render(): View
    {
        $this->guardFeature();

        if (! $this->canOnboard()) {
            $this->onboardingSnippet = null;
            $this->onboardingExpiresAt = null;
        }

        return view('livewire.postmaster.spoke-map', [
            'spokes' => collect($this->spokes()),
            'canOnboard' => $this->canOnboard(),
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

        $staleAfter = max(60, (int) config('capstan.postmaster.map.stale_after_seconds', 300));
        $staleBefore = now()->subSeconds($staleAfter);

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

    private function canOnboard(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && in_array($user->org_role, [OrgRole::Owner, OrgRole::Admin], true);
    }

    private function guardFeature(): void
    {
        abort_unless(Feature::active(Postmaster::class), 404);
    }

    private function onboardingRateLimitKey(): string
    {
        return 'postmaster-onboarding:'.(request()->ip() ?: 'unknown');
    }
}
