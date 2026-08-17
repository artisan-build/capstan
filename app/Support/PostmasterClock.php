<?php

namespace App\Support;

use RuntimeException;

/**
 * Postmaster signs and stores envelope timestamps as naive UTC. Database round-trips
 * reinterpret stored values in the application timezone, so any timezone other than
 * UTC silently invalidates every signature. Fail loudly instead.
 */
final class PostmasterClock
{
    public static function assertUtc(): void
    {
        $timezone = config('app.timezone');

        if ($timezone === 'UTC') {
            return;
        }

        throw new RuntimeException(sprintf(
            'Postmaster requires app.timezone to be UTC; it is [%s]. Restore the shipped value in config/app.php — '
            .'envelope signatures are computed over UTC timestamps and any other timezone breaks signing and verification.',
            is_scalar($timezone) ? (string) $timezone : gettype($timezone),
        ));
    }
}
