<?php

namespace App\Work;

use App\Models\WorkEvidence;
use Closure;
use Illuminate\Support\Facades\Storage;

final class WorkEvidenceAttemptLog
{
    /** @var list<string> */
    private array $storageKeys = [];

    public function record(string $storageKey): void
    {
        $this->storageKeys[] = $storageKey;
    }

    /**
     * @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    public function settle(Closure $operation): mixed
    {
        try {
            return $operation();
        } finally {
            $this->cleanupUnlinked();
        }
    }

    private function cleanupUnlinked(): void
    {
        $attempted = array_values(array_unique($this->storageKeys));
        if ($attempted === []) {
            return;
        }

        $linked = WorkEvidence::query()->whereIn('storage_key', $attempted)->pluck('storage_key')->all();
        foreach (array_diff($attempted, $linked) as $storageKey) {
            rescue(fn () => Storage::disk()->delete($storageKey), null, false);
        }
    }
}
