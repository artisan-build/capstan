<?php

namespace App\Http\Controllers\Api;

use App\Features\Work;
use App\Http\ApiError;
use App\Http\Controllers\Controller;
use App\Mcp\DurableWriteCoordinator;
use App\Mcp\OwnerSubject;
use App\Work\WorkOrchestrationSurface;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;

final class WorkOrchestrationWorkerController extends Controller
{
    public function __invoke(Request $request, string $item, WorkOrchestrationSurface $surface, DurableWriteCoordinator $writes): JsonResponse
    {
        abort_unless(Feature::active(Work::class), 404);
        abort_unless($request->hasValidSignature(), 403);
        $context = $surface->claimed($item, (int) $request->query('fence'));

        if ($request->isMethod('GET')) {
            return new JsonResponse(['context' => $surface->read($context, 0, CarbonImmutable::now())]);
        }

        $body = json_decode($request->getContent(), true);
        abort_unless(is_array($body) && ! array_is_list($body)
            && array_diff(array_keys($body), ['action', 'input', 'idempotency_key']) === []
            && is_string($body['action'] ?? null) && is_array($body['input'] ?? null) && ! array_is_list($body['input'])
            && is_string($body['idempotency_key'] ?? null) && $body['idempotency_key'] !== '' && strlen($body['idempotency_key']) <= 255, 422);

        // Prove the signed authority before acquiring a replay record; the action proves it again under locks.
        $surface->read($context, 0, CarbonImmutable::now());
        $surface->validate($body['action'], $body['input']);
        $result = $writes->run(
            new OwnerSubject('work_turn', $item.':'.$context->itemFence, $context->actorId),
            'orchestrate',
            $body['idempotency_key'],
            ['action' => $body['action'], 'input' => $body['input']],
            'work_orchestration',
            fn (): array => $surface->act($context, $body['action'], $body['input'], CarbonImmutable::now()),
        );

        if (($result['outcome'] ?? null) === 'refused') {
            $reason = (string) ($result['reason'] ?? 'orchestration_forbidden');

            return ApiError::response($reason === 'invalid_orchestration_action' ? 422 : 403, $reason, 'The orchestration action was refused.');
        }

        return new JsonResponse($result);
    }
}
