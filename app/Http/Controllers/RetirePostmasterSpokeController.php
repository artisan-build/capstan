<?php

namespace App\Http\Controllers;

use App\Postmaster\RetireSpoke;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RetirePostmasterSpokeController
{
    public function __invoke(Request $request, int $spoke, RetireSpoke $retire): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $retire->handle($spoke, (string) $user->getKey(), CarbonImmutable::now());
        } catch (DomainException $exception) {
            return back()->withErrors(['retireSpoke' => $exception->getMessage()]);
        }

        return to_route('postmaster.map')->with('status', __('Postmaster spoke retired.'));
    }
}
