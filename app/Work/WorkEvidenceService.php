<?php

namespace App\Work;

use App\Http\ApiErrorException;
use App\Machine\TeamMemberships;
use App\Models\WorkEvidence;
use App\Models\WorkItem;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class WorkEvidenceService
{
    /** @var array<string, string> */
    private const array TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'mp4' => 'video/mp4',
        'txt' => 'text/plain',
        'json' => 'application/json',
    ];

    public function __construct(
        private WorkOrchestrationLock $locks,
        private TeamMemberships $memberships,
    ) {}

    /** @return array{extension: string, content_type: string, size_bytes: int, content_hash: string} */
    public function inspect(string $filename, string $bytes): array
    {
        $size = strlen($bytes);
        $maximum = max(1, (int) config('capstan.work.evidence.max_bytes', 10 * 1024 * 1024));
        if ($size < 1 || $size > $maximum || strlen($filename) > 255 || str_contains($filename, "\0")) {
            $this->invalid('Evidence must be a nonempty file within the configured size limit.');
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $expected = self::TYPES[$extension] ?? null;
        if ($expected === null) {
            $this->invalid('Evidence file type is not allowed.');
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($extension === 'json' && in_array($detected, ['application/json', 'text/plain'], true)) {
            json_decode($bytes, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $detected = 'application/json';
            }
        }
        if (! is_string($detected) || ! hash_equals($expected, $detected)) {
            $this->invalid('Evidence content does not match its filename extension.');
        }

        return [
            'extension' => $extension,
            'content_type' => $expected,
            'size_bytes' => $size,
            'content_hash' => hash('sha256', $bytes),
        ];
    }

    public function throttle(string $actor): void
    {
        $key = 'work-evidence:'.hash('sha256', $actor);
        $maximum = max(1, (int) config('capstan.work.evidence.uploads_per_minute', 30));
        if (RateLimiter::tooManyAttempts($key, $maximum)) {
            throw new ApiErrorException(429, 'evidence_rate_limited', 'Too many evidence uploads.');
        }
        RateLimiter::hit($key, 60);
    }

    public function authorizeInteractive(User $actor, int $teamId, string $taskId, string $itemId): WorkItem
    {
        if ($actor->status !== 'active' || ! $this->memberships->belongsTo($actor, $teamId)) {
            throw new ApiErrorException(403, 'evidence_forbidden', 'Work evidence upload is unavailable.');
        }
        $item = WorkItem::query()->whereKey($itemId)
            ->where('work_task_id', $taskId)
            ->whereHas('workTask', fn ($query) => $query->where('team_id', $teamId)->whereNull('cancelled_at'))
            ->first();
        if (! $item instanceof WorkItem) {
            throw new ApiErrorException(404, 'item_unavailable', 'Work item is unavailable.');
        }

        return $item;
    }

    /**
     * @param  array{extension: string, content_type: string, size_bytes: int, content_hash: string}  $metadata
     */
    public function store(WorkItem $item, string $bytes, array $metadata): WorkEvidence
    {
        $id = (string) Str::uuid7();
        $storageKey = 'work-evidence/private/'.$id;
        $storageAttempted = false;

        try {
            return DB::transaction(function () use ($item, $bytes, $metadata, $id, $storageKey, &$storageAttempted): WorkEvidence {
                $this->locks->tasks($item->work_task_id);
                $locked = WorkItem::query()->whereKey($item->id)->where('work_task_id', $item->work_task_id)->lockForUpdate()->first();
                if (! $locked instanceof WorkItem || $locked->workTask()->whereNotNull('cancelled_at')->exists()) {
                    throw new ApiErrorException(409, 'item_unavailable', 'Work item is unavailable.');
                }
                $maximum = max(1, (int) config('capstan.work.evidence.max_per_item', 20));
                if ($locked->evidence()->count() >= $maximum) {
                    throw new ApiErrorException(422, 'evidence_count_exceeded', 'The Work item evidence limit has been reached.');
                }

                $disk = Storage::disk();
                $storageAttempted = true;
                if (! $disk->put($storageKey, $bytes, ['visibility' => 'private'])) {
                    throw new ApiErrorException(503, 'evidence_storage_failed', 'Evidence could not be stored.');
                }

                $evidence = new WorkEvidence;
                $evidence->forceFill([
                    'id' => $id,
                    'work_task_id' => $locked->work_task_id,
                    'work_item_id' => $locked->id,
                    'public_token' => bin2hex(random_bytes(32)),
                    'storage_key' => $storageKey,
                    'content_type' => $metadata['content_type'],
                    'extension' => $metadata['extension'],
                    'size_bytes' => $metadata['size_bytes'],
                ])->save();

                return $evidence;
            }, 5);
        } catch (Throwable $exception) {
            if ($storageAttempted) {
                rescue(fn () => Storage::disk()->delete($storageKey), null, false);
            }
            throw $exception;
        }
    }

    /** @return array{id: string, task_id: string, item_id: string, url: string, content_type: string, size_bytes: int, created_at: string} */
    public function representation(WorkEvidence $evidence): array
    {
        return [
            'id' => $evidence->id,
            'task_id' => $evidence->work_task_id,
            'item_id' => $evidence->work_item_id,
            'url' => route('work.evidence.show', ['token' => $evidence->public_token]),
            'content_type' => $evidence->content_type,
            'size_bytes' => $evidence->size_bytes,
            'created_at' => $evidence->created_at->toJSON(),
        ];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => [$message]]);
    }
}
