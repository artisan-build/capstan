<?php

namespace App\Livewire\Work;

use App\Features\Work;
use App\Models\WorkTask;
use ArtisanBuild\BuiltForCloud\Contracts\IdentityContext;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts::app')]
class Index extends Component
{
    public function mount(): void
    {
        $this->guardAccess();
    }

    public function render(): View
    {
        $this->guardAccess();

        return view('livewire.work.index', [
            'tasks' => WorkTask::query()->orderByDesc('id')->limit(25)
                ->with([
                    'items' => fn ($query) => $query->orderBy('id')->limit(25),
                    'items.milestones' => fn ($query) => $query->orderByDesc('id')->limit(1),
                    'briefRevisions' => fn ($query) => $query->reorder()->orderByDesc('sequence')->limit(1),
                    'steers' => fn ($query) => $query->orderByDesc('id')->limit(10),
                ])->withCount('items')->get(),
        ]);
    }

    private function guardAccess(): void
    {
        abort_unless(Feature::active(Work::class), 404);
        $user = request()->user();
        abort_unless($user instanceof User && $user->status === 'active'
            && ! OffboardedSubject::userIsOffboarded((string) $user->getKey())
            && resolve(IdentityContext::class)->canUseProduct(), 403);
    }
}
