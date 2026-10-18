<?php

namespace App\Http\Controllers;

use App\Postmaster\PendingInstallLinks;
use Illuminate\Http\Response;

final class PostmasterInstallController
{
    private const string UNAVAILABLE = 'echo "This install link was already used or has expired. Generate a new one." >&2; exit 1';

    public function __invoke(string $token, PendingInstallLinks $links): Response
    {
        $script = $links->consume($token);

        return response(
            $script ?? self::UNAVAILABLE."\n",
            $script === null ? 410 : 200,
            [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
