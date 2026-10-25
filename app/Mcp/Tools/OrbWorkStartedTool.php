<?php

namespace App\Mcp\Tools;

use App\Auth\AmpClaimBinder;
use App\Auth\AmpClaimContext;
use App\Work\WorkWorkerService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('work_started')]
#[Description('Records that work started and opens the bound claim deadline from the task timebox.')]
final class OrbWorkStartedTool extends OrbTool
{
    public function handle(Request $request, WorkWorkerService $workers, AmpClaimBinder $claims): Response|ResponseFactory
    {
        $this->requireExactArguments($request, []);
        $context = AmpClaimContext::current();

        return $this->forCurrentClaim(function () use ($workers, $claims, $context): array {
            $result = $workers->started(
                $context->itemId,
                $context->fence,
                $context->launchNonce,
                fn ($item) => $claims->assertCurrent($context, $item),
            );

            return [
                'milestone_id' => $result['milestone']->id,
                'lease_until' => $result['lease_until']->toISOString(),
            ];
        });
    }
}
