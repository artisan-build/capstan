<?php

namespace App\Mcp;

class WriteFaultInjector
{
    public function beforeClaim(string $tool): void {}

    public function beforeOperation(string $tool): void {}

    public function beforeSettlement(string $tool): void {}

    public function beforeAcceptedRecoverySettlement(string $tool): void {}

    public function beforeFailureSettlement(string $tool): void {}
}
