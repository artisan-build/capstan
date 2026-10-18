<?php

namespace App\Postmaster;

use Closure;
use Illuminate\Http\Request;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Records\Request as NightwatchRequest;
use Laravel\Nightwatch\State\RequestState;
use WeakMap;

final class ProtectInstallCapabilityTelemetry
{
    /** @var WeakMap<Core<RequestState>, bool>|null */
    private static ?WeakMap $redactors = null;

    public function handle(Request $request, Closure $next): mixed
    {
        $nightwatch = $this->nightwatch();

        // Nightwatch's global middleware has already seen the raw path. Replace its
        // execution preview before the first query, log, or exception can record it.
        $nightwatch->captureRequestPreview(Request::create(
            '/postmaster/install/[redacted]',
            $request->getMethod(),
        ));

        self::$redactors ??= new WeakMap;

        if (! isset(self::$redactors[$nightwatch])) {
            $nightwatch->redactRequests(function (NightwatchRequest $record): bool {
                if ($record->routeName !== 'postmaster.install') {
                    return false;
                }

                $record->url = self::redactUrl($record->url);

                return true;
            });
            self::$redactors[$nightwatch] = true;
        }

        return $next($request);
    }

    /** @return Core<RequestState> */
    private function nightwatch(): Core
    {
        return resolve(Core::class);
    }

    public static function redactUrl(string $url): string
    {
        return (string) preg_replace(
            '#(/postmaster/install/)[^/?]+#',
            '$1[redacted]',
            $url,
            1,
        );
    }
}
