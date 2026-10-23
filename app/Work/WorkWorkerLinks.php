<?php

namespace App\Work;

use App\Models\WorkItem;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

final class WorkWorkerLinks
{
    /** @return array<string, string> */
    public function forItem(WorkItem $item): array
    {
        if ($item->launch_nonce === null) {
            $item->forceFill(['launch_nonce' => (string) Str::uuid7()])->save();
        }
        $grace = max(0, (int) config('capstan.work.link_grace_seconds', 60));
        $expires = $item->lease_until->min($item->claimed_at->addMinutes($item->workTask->timebox_minutes))->addSeconds($grace);
        $links = [];

        foreach (['brief', 'milestone', 'steers', 'evidence'] as $action) {
            $links[$action] = URL::temporarySignedRoute('api.work.worker.'.$action, $expires, [
                'item' => $item->id,
                'fence' => $item->fence,
                'nonce' => $item->launch_nonce,
            ]);
        }

        if ($item->role === WorkItem::ROLE_ORCHESTRATOR) {
            foreach (['orchestration', 'orchestrate'] as $action) {
                $links[$action] = URL::temporarySignedRoute('api.work.worker.'.$action, $expires, [
                    'item' => $item->id,
                    'fence' => $item->fence,
                    'nonce' => $item->launch_nonce,
                ]);
            }
        }

        return $links;
    }
}
