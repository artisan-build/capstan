<?php

namespace App\Mcp\Tools;

use App\Auth\AmpClaimBinder;
use App\Auth\AmpClaimContext;
use App\Models\WorkFinish;
use App\Work\WorkFinishService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('work_finished')]
#[Description('Declares the bound work ready for orchestrator verification without closing it.')]
final class OrbWorkFinishedTool extends OrbTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'writeup' => $schema->string()->min(1)->max(WorkFinish::WRITEUP_MAX_LENGTH)->required(),
            'pr' => $schema->object([
                'repo' => $schema->string()->min(3)->max(255)->required(),
                'number' => $schema->integer()->min(1)->required(),
                'url' => $schema->string()->min(1)->max(2048)->required(),
            ])->withoutAdditionalProperties()->required(),
        ];
    }

    public function handle(Request $request, WorkFinishService $finishes, AmpClaimBinder $claims): Response|ResponseFactory
    {
        $this->requireExactArguments($request, ['writeup', 'pr']);
        $payload = json_decode(request()->getContent());
        $arguments = $payload instanceof \stdClass && isset($payload->params->arguments)
            ? $payload->params->arguments
            : null;
        $rawPr = $arguments instanceof \stdClass && isset($arguments->pr) && $arguments->pr instanceof \stdClass
            ? get_object_vars($arguments->pr)
            : [];
        $input = $request->validate([
            'writeup' => ['required', 'string', 'max:'.WorkFinish::WRITEUP_MAX_LENGTH],
            'pr' => ['required', 'array'],
            'pr.repo' => ['required', 'string', 'max:255'],
            'pr.number' => ['required', 'integer', 'min:1'],
            'pr.url' => ['required', 'string', 'max:2048'],
        ]);
        $context = AmpClaimContext::current();

        return $this->forCurrentClaim(function () use ($finishes, $claims, $context, $input, $rawPr): array {
            $finish = $finishes->declare(
                $context->itemId,
                $context->fence,
                $context->launchNonce,
                $input['writeup'],
                $rawPr,
                fn ($item) => $claims->assertCurrent($context, $item),
            );

            return [
                'finish_id' => $finish->id,
                'item_id' => $finish->work_item_id,
                'state' => 'awaiting_verification',
            ];
        });
    }
}
