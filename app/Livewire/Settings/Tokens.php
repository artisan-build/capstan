<?php

namespace App\Livewire\Settings;

use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Token settings')]
class Tokens extends Component
{
    public function revoke(int $tokenId): void
    {
        /** @var PersonalAccessToken|null $token */
        $token = Auth::user()->tokens()->whereKey($tokenId)->first();

        abort_unless($token instanceof PersonalAccessToken, 403);

        $token->delete();

        Flux::toast(variant: 'success', text: __('Token revoked.'));
    }

    public function render(): View
    {
        /** @var Collection<int, PersonalAccessToken> $tokens */
        $tokens = Auth::user()->tokens()
            ->select(['id', 'tokenable_type', 'tokenable_id', 'name', 'created_at', 'last_used_at'])
            ->latest()
            ->get();

        return view('livewire.settings.tokens', [
            'tokens' => $tokens,
        ]);
    }
}
