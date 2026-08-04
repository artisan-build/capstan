<?php

namespace App\Console\Commands;

use App\Models\DeviceCode;
use Illuminate\Console\Command;

class PruneDeviceCodes extends Command
{
    protected $signature = 'capstan:prune-device-codes';

    protected $description = 'Delete expired and denied device-authorization grants.';

    public function handle(): int
    {
        $deleted = DeviceCode::query()
            ->where('expires_at', '<=', now())
            ->orWhere('status', 'denied')
            ->delete();

        $this->info("Pruned {$deleted} device code(s).");

        return self::SUCCESS;
    }
}
