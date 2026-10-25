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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('work_brief')]
#[Description('Fetches the latest pinned brief for the Amp claim bound to this request.')]
#[IsReadOnly]
final class OrbWorkBriefTool extends OrbTool
{
    public function handle(Request $request, WorkWorkerService $workers, AmpClaimBinder $claims): Response|ResponseFactory
    {
        $this->requireExactArguments($request, []);
        $context = AmpClaimContext::current();

        return $this->forCurrentClaim(fn (): array => $workers->brief(
            $context->itemId,
            $context->fence,
            $context->launchNonce,
            afterIdentified: fn ($item) => $claims->assertCurrent($context, $item),
        ));
    }
}
