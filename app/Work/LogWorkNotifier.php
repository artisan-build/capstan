<?php

namespace App\Work;

use App\Models\Spoke;
use App\Models\WorkItem;
use Illuminate\Support\Facades\Log;

final class LogWorkNotifier implements WorkNotifier
{
    public function stalled(WorkItem $item): void
    {
        Log::warning('Work item stalled.', ['item_id' => $item->id, 'fence' => $item->fence]);
    }

    public function missedHeartbeat(Spoke $spoke): void
    {
        Log::warning('Work host missed heartbeat.', ['spoke_id' => $spoke->id]);
    }

    public function waitingOnHost(WorkItem $item): void
    {
        Log::warning('Work item waiting on pinned host.', ['item_id' => $item->id]);
    }
}
