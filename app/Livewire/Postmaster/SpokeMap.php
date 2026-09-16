<?php

namespace App\Livewire\Postmaster;

use App\Enums\SpokeLiveness;
use App\Enums\SpokeMapStatus;
use App\Features\Postmaster;
use App\Models\Spoke;
use App\Postmaster\OnboardingSnippet;
use ArtisanBuild\BuiltForCloud\Contracts\IdentityContext;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
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

    public function render(): View
    {
        $this->guardFeature();

        return view('livewire.postmaster.spoke-map', [
            'spokes' => collect($this->spokes()),
        ]);
    }

    public function generateOnboardingSnippet(OnboardingSnippet $snippet, IdentityContext $identity): void
    {
        $this->guardFeature();
        abort_unless($identity->canUseProduct(), 403);

        $user = request()->user();
        abort_unless($user instanceof User, 401);

        $key = 'postmaster-onboarding:'.(request()->ip() ?: 'unknown');
        abort_if(RateLimiter::tooManyAttempts($key, 15), 429);
        RateLimiter::hit($key, 60);

        $this->onboardingSnippet = $snippet->generate(request(), (string) $user->getKey());
        $this->onboardingExpiresAt = now()->addSeconds(600)->getTimestamp();
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
        $query = Spoke::query()
            ->withCount('inboxes');

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
                'owner_name' => $spoke->actor_id,
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

    private function guardFeature(): void
    {
        abort_unless(Feature::active(Postmaster::class), 404);
    }
}
