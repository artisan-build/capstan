<?php

namespace App\Mcp\Tools;

use App\Mcp\DurableWriteCoordinator;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\ToolResponse;
use App\Work\WorkEvidenceAttemptLog;
use App\Work\WorkEvidenceService;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('upload_work_evidence')]
#[Description('Idempotently uploads one bounded base64-encoded evidence file to a Work item and returns its permanent public-by-link URL.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Write)]
final class UploadWorkEvidenceTool extends CapstanTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;

    public function schema(JsonSchema $schema): array
    {
        $maximum = (int) ceil(max(1, (int) config('capstan.work.evidence.max_bytes', 10 * 1024 * 1024)) / 3) * 4;

        return [
            'team_id' => $schema->integer()->min(1)->required(),
            'task_id' => $schema->string()->required(),
            'item_id' => $schema->string()->required(),
            'filename' => $schema->string()->min(1)->max(255)->required(),
            'bytes_base64' => $schema->string()->min(4)->max($maximum)->required(),
            'idempotency_key' => $schema->string()->min(1)->max(255)->required(),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'evidence' => $schema->object([
                'id' => $schema->string()->required(),
                'task_id' => $schema->string()->required(),
                'item_id' => $schema->string()->required(),
                'url' => $schema->string()->required(),
                'content_type' => $schema->string()->required(),
                'size_bytes' => $schema->integer()->required(),
                'created_at' => $schema->string()->required(),
            ])->withoutAdditionalProperties(),
            'outcome' => $schema->string()->enum(['accepted', 'already_accepted', 'refused', 'in_progress', 'outcome_unknown'])->required(),
            'reason' => $schema->string(),
        ];
    }

    public function handle(
        Request $request,
        OwnerSubjectResolver $owners,
        DurableWriteCoordinator $writes,
        WorkEvidenceService $evidence,
    ): ResponseFactory {
        $this->requireExactArguments($request, ['team_id', 'task_id', 'item_id', 'filename', 'bytes_base64', 'idempotency_key']);
        $maximum = max(1, (int) config('capstan.work.evidence.max_bytes', 10 * 1024 * 1024));
        $encodedMaximum = (int) ceil($maximum / 3) * 4;
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'min:1'],
            'task_id' => ['required', 'string', 'uuid'],
            'item_id' => ['required', 'string', 'uuid'],
            'filename' => ['required', 'string', 'min:1', 'max:255'],
            'bytes_base64' => ['required', 'string', 'min:4', 'max:'.$encodedMaximum],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
        ]);
        $owner = $owners->resolve();
        $actor = User::query()->find($owner->actorId);
        if (! $actor instanceof User || $actor->status !== 'active' || ! RolePolicy::canUseProduct($actor->role)
            || OffboardedSubject::userIsOffboarded($owner->actorId)) {
            throw new AuthorizationException('Only an admitted team member may upload Work evidence.');
        }
        $item = $evidence->authorizeInteractive($actor, $validated['team_id'], $validated['task_id'], $validated['item_id']);
        $bytes = base64_decode($validated['bytes_base64'], true);
        if (! is_string($bytes) || strlen($bytes) > $maximum || ! hash_equals(base64_encode($bytes), $validated['bytes_base64'])) {
            throw ValidationException::withMessages(['bytes_base64' => ['Evidence must use canonical bounded base64 encoding.']]);
        }
        $metadata = $evidence->inspect($validated['filename'], $bytes);
        $attempts = new WorkEvidenceAttemptLog;

        $result = $attempts->settle(
            fn (): array => $writes->run(
                owner: $owner,
                tool: 'upload_work_evidence',
                idempotencyKey: $validated['idempotency_key'],
                intent: [
                    'team_id' => $validated['team_id'],
                    'task_id' => $validated['task_id'],
                    'item_id' => $validated['item_id'],
                    'filename' => $validated['filename'],
                    'content_hash' => $metadata['content_hash'],
                    'size_bytes' => $metadata['size_bytes'],
                ],
                targetType: 'work_evidence',
                operation: function () use ($evidence, $item, $bytes, $metadata, $attempts): array {
                    $stored = $evidence->store($item, $bytes, $metadata, $attempts);

                    return ['evidence' => $evidence->representation($stored)];
                },
                admission: fn () => $evidence->throttle('actor:'.$owner->actorId),
            ),
        );

        return ToolResponse::structured($result);
    }
}
