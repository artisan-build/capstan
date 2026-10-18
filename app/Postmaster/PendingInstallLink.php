<?php

namespace App\Postmaster;

use Carbon\CarbonImmutable;

final readonly class PendingInstallLink
{
    public function __construct(
        public string $command,
        public string $script,
        public CarbonImmutable $expiresAt,
    ) {}
}
