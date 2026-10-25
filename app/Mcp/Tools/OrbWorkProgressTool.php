<?php

namespace App\Mcp\Tools;

use App\Auth\AmpClaimBinder;
use App\Auth\AmpClaimContext;
use App\Models\WorkItem;
use App\Work\WorkWorkerService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('work_progress')]
#[Description('Records a short progress summary and extends the bound claim deadline from the task timebox.')]
final class OrbWorkProgressTool extends OrbTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->min(1)->max(WorkItem::LAST_PROGRESS_MAX_LENGTH)->required(),
        ];
    }

    public function handle(Request $request, WorkWorkerService $workers, AmpClaimBinder $claims): Response|ResponseFactory
    {
        $this->requireExactArguments($request, ['summary']);
        $input = $request->validate([
            'summary' => ['required', 'string', 'max:'.WorkItem::LAST_PROGRESS_MAX_LENGTH, function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || trim($value) === '') {
                    $fail('The progress summary must not be empty.');
                }
            }],
        ]);
        $context = AmpClaimContext::current();

        return $this->forCurrentClaim(function () use ($workers, $claims, $context, $input): array {
            $result = $workers->progress(
                $context->itemId,
                $context->fence,
                $context->launchNonce,
                $input['summary'],
                fn ($item) => $claims->assertCurrent($context, $item),
            );

            return [
                'milestone_id' => $result['milestone']->id,
                'lease_until' => $result['lease_until']->toISOString(),
            ];
        });
    }
}
