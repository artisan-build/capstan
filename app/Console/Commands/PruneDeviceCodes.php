<?php

namespace App\Console\Commands;

use App\Enums\DeviceCodeStatus;
use App\Models\AuthorizationCode;
use App\Models\DeviceCode;
use Illuminate\Console\Command;

class PruneDeviceCodes extends Command
{
    protected $signature = 'capstan:prune-device-codes';

    protected $description = 'Delete expired and denied device-authorization grants and stale CLI authorization codes.';

    public function handle(): int
    {
        $deleted = DeviceCode::query()
            ->where('expires_at', '<=', now())
            ->orWhere(function ($query): void {
                $query->where('status', DeviceCodeStatus::Denied->value)
                    ->where('updated_at', '<=', now()->subSeconds(DeviceCode::LIFETIME_SECONDS));
            })
            ->delete();

        $this->info("Pruned {$deleted} device code(s).");

        $prunedCodes = AuthorizationCode::query()
            ->where('expires_at', '<=', now())
            ->delete();

        $this->info("Pruned {$prunedCodes} authorization code(s).");

        return self::SUCCESS;
    }
}
