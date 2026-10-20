<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkItemState;
use App\Http\ApiError;
use App\Http\ApiErrorException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateBoundCredential;
use App\Models\Team;
use App\Models\WorkItem;
use App\Work\WorkDrainService;
use App\Work\WorkWorkerLinks;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class WorkDrainController extends Controller
{
    public const int MAX_BATCH = 25;

    public function claim(Request $request, Team $team, WorkDrainService $drain): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'capacity' => ['required', 'integer', 'min:0', 'max:'.self::MAX_BATCH],
            'roles' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_BATCH],
            'roles.*' => ['required', 'string', 'max:64', 'distinct'],
            'capabilities' => ['present', 'array', 'list', 'max:'.self::MAX_BATCH],
            'capabilities.*' => ['required', 'string', 'max:64', 'distinct'],
            'role_providers' => ['sometimes', 'array', 'max:'.self::MAX_BATCH],
            'role_providers.*' => ['required', 'string', 'max:64', 'regex:/\A[a-z0-9][a-z0-9._-]*\z/D'],
            'current_claimed_item_count' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_BATCH],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        /** @var array{capacity: int, roles: non-empty-list<string>, capabilities: list<string>, role_providers: array<string, string>, current_claimed_item_count: int} $validated */
        $validated = $validator->validated();
        $validated += ['role_providers' => [], 'current_claimed_item_count' => 0];
        $credential = AuthenticateBoundCredential::credential($request);
        try {
            $items = $drain->claim(
                $team->id,
                AuthenticateBoundCredential::actorId($request),
                $credential->id,
                $validated['capacity'],
                $validated['roles'],
                $validated['capabilities'],
                $validated['role_providers'],
                $validated['current_claimed_item_count'],
                CarbonImmutable::now(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->validationError(['runtime' => [$exception->getMessage()]]);
        }

        return new JsonResponse([
            'items' => array_map($this->claimItem(...), $items),
        ]);
    }

    public function transition(Request $request, Team $team, WorkDrainService $drain): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'item_id' => ['required', 'uuid'],
            'fence' => ['required', 'integer', 'min:1'],
            'state' => ['required', 'string', Rule::in([
                WorkItemState::Delivered->value,
                WorkItemState::Routed->value,
                'released',
                WorkItemState::Failed->value,
            ])],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        /** @var array{item_id: string, fence: int, state: string} $validated */
        $validated = $validator->validated();
        $credential = AuthenticateBoundCredential::credential($request);

        try {
            $item = $drain->transition(
                $team->id,
                AuthenticateBoundCredential::actorId($request),
                $credential->id,
                $validated['item_id'],
                $validated['fence'],
                $validated['state'],
                CarbonImmutable::now(),
            );
        } catch (ApiErrorException $exception) {
            return $exception->toResponse($request);
        }

        return new JsonResponse(['item' => $this->currentItem($item)]);
    }

    public function renew(Request $request, Team $team, WorkDrainService $drain): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_BATCH],
            'items.*' => ['required', 'array'],
            'items.*.item_id' => ['required', 'uuid', 'distinct'],
            'items.*.fence' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        /** @var array{items: list<array{item_id: string, fence: int}>} $validated */
        $validated = $validator->validated();
        $credential = AuthenticateBoundCredential::credential($request);
        $results = $drain->renew(
            $team->id,
            AuthenticateBoundCredential::actorId($request),
            $credential->id,
            $validated['items'],
            CarbonImmutable::now(),
        );

        return new JsonResponse([
            'results' => array_map(function (array $result): array {
                $wire = [
                    'item_id' => $result['item_id'],
                    'status' => $result['status'],
                ];

                if (isset($result['item'])) {
                    $wire['item'] = [...$this->currentItem($result['item']), 'links' => resolve(WorkWorkerLinks::class)->forItem($result['item'])];
                }

                return $wire;
            }, $results),
        ]);
    }

    /** @return array<string, mixed> */
    private function claimItem(WorkItem $item): array
    {
        return array_filter([
            'id' => $item->id,
            'task_id' => $item->work_task_id,
            'role' => $item->role,
            'fence' => $item->fence,
            'lease_until' => $item->lease_until->toISOString(),
            'links' => resolve(WorkWorkerLinks::class)->forItem($item),
            'session_ref' => $item->session_ref,
            'seat_fence' => $item->seat_fence,
        ], fn ($value): bool => $value !== null);
    }

    /** @return array<string, int|string> */
    private function currentItem(WorkItem $item): array
    {
        $wire = [
            'id' => $item->id,
            'task_id' => $item->work_task_id,
            'role' => $item->role,
            'state' => $item->state->value,
            'fence' => $item->fence,
        ];

        if ($item->lease_until !== null) {
            $wire['lease_until'] = $item->lease_until->toISOString();
        }

        return $wire;
    }

    /** @param array<string, mixed> $errors */
    private function validationError(array $errors): JsonResponse
    {
        return ApiError::response(422, 'validation_failed', 'The given data was invalid.', [
            'errors' => $errors,
        ]);
    }
}
