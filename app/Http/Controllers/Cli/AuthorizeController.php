<?php

namespace App\Http\Controllers\Cli;

use App\Cli\LoopbackRedirect;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CliTokenNames;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthorizeController extends Controller
{
    public function show(Request $request): View
    {
        $validated = $request->validate([
            'redirect_uri' => ['required', 'string'],
            'state' => ['nullable', 'string'],
            'label' => ['nullable', 'string', 'max:64'],
        ]);

        $redirectUri = $validated['redirect_uri'];

        abort_unless(LoopbackRedirect::isValid($redirectUri), 422, __('The CLI callback address is not a permitted loopback URL.'));

        /** @var User $user */
        $user = Auth::user();

        return view('cli.authorize', [
            'email' => $user->email,
            'redirectUri' => $redirectUri,
            'state' => $validated['state'] ?? '',
            'label' => CliTokenNames::sanitizeLabel($validated['label'] ?? null) ?? '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'redirect_uri' => ['required', 'string'],
            'state' => ['nullable', 'string'],
            'label' => ['nullable', 'string', 'max:64'],
            'action' => ['required', 'string', 'in:approve,deny'],
        ]);

        $redirectUri = $validated['redirect_uri'];

        abort_unless(LoopbackRedirect::isValid($redirectUri), 422, __('The CLI callback address is not a permitted loopback URL.'));

        $state = $validated['state'] ?? '';

        if ($validated['action'] === 'deny') {
            return redirect()->away(LoopbackRedirect::appendQuery($redirectUri, [
                'error' => 'access_denied',
                'state' => $state,
            ]));
        }

        /** @var User $user */
        $user = Auth::user();
        $token = $user->createToken(CliTokenNames::forLabel($validated['label'] ?? null))->plainTextToken;

        return redirect()->away(LoopbackRedirect::appendQuery($redirectUri, [
            'token' => $token,
            'state' => $state,
        ]));
    }
}
