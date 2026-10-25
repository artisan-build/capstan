<?php

namespace App\Http\Controllers\Api;

use App\Casts\WorkPullRequest;
use App\Enums\WorkMilestoneKind;
use App\Http\Controllers\Controller;
use App\Models\WorkItem;
use App\Work\WorkEvidenceService;
use App\Work\WorkPreemptionService;
use App\Work\WorkWorkerService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class WorkWorkerController extends Controller
{
    public function __invoke(Request $request, string $item, WorkWorkerService $workers, WorkEvidenceService $evidence): Response
    {
        abort_unless($request->hasValidSignatureWhileIgnoring(['model']), 403);
        $fence = (int) $request->query('fence');
        $nonce = (string) $request->query('nonce');

        try {
            if ($request->routeIs('api.work.worker.evidence')) {
                $keys = array_keys(array_merge($request->request->all(), $request->files->all()));
                sort($keys);
                abort_if($keys !== ['file'], 422, 'Expected one file field.');
                $file = $request->file('file');
                abort_unless($file instanceof UploadedFile && $file->isValid(), 422, 'Evidence file is invalid.');
                $bytes = $file->get();
                abort_unless(is_string($bytes), 422, 'Evidence file could not be read.');
                $stored = $workers->evidence($item, $fence, $file->getClientOriginalName(), $bytes, $nonce);

                return response()->json(['evidence' => $evidence->representation($stored)], 201);
            }
            if ($request->routeIs('api.work.worker.brief')) {
                $model = $request->query('model');
                abort_if($model !== null && (! is_string($model) || trim($model) === '' || strlen($model) > 255), 422, 'Agent model is invalid.');
                $brief = $workers->brief($item, $fence, $nonce, $model);
                if ($request->prefers(['application/json', 'text/markdown']) === 'text/markdown') {
                    $release = isset($brief['release_requested']) ? "\n\nRelease requested for P0 work. Save your branch/progress and post released before ".$brief['release_requested']['deadline'].'.' : '';

                    return response('# '.$brief['task']['title']."\n\nRole: ".$brief['item']['role']."\n\n".($brief['revision']['content'] ?? '').$release, 200, ['Content-Type' => 'text/markdown; charset=UTF-8']);
                }

                return response()->json($brief);
            }
            if ($request->routeIs('api.work.worker.steers')) {
                return response()->json($workers->guarded($item, $fence, fn (WorkItem $claimed): array => [
                    'steers' => $workers->steers($item, $fence, $nonce),
                    ...WorkPreemptionService::representation($claimed),
                ], nonce: $nonce));
            }

            $object = json_decode($request->getContent());
            abort_unless($object instanceof \stdClass && isset($object->payload) && $object->payload instanceof \stdClass, 422, 'Expected an object with a payload object.');
            abort_if(array_diff(array_keys((array) $object), ['kind', 'payload']) !== [], 422, 'Unknown keys.');
            abort_if(strlen(json_encode($object->payload, JSON_THROW_ON_ERROR)) > 16384, 422, 'Payload too large.');
            $rawPr = property_exists($object->payload, 'pr') ? $object->payload->pr : null;
            if ($rawPr !== null) {
                abort_unless($rawPr instanceof \stdClass, 422, 'PR metadata must be an object.');
                $prKeys = array_keys(get_object_vars($rawPr));
                sort($prKeys);
                abort_if($prKeys !== ['number', 'repo', 'url'], 422, 'PR metadata has invalid fields.');
            }
            $payload = (array) $object->payload;
            if ($rawPr instanceof \stdClass) {
                $payload['pr'] = (array) $rawPr;
            }
            $data = Validator::make(['kind' => $object->kind ?? null, 'payload' => $payload], [
                'kind' => ['required', Rule::enum(WorkMilestoneKind::class)],
                'payload' => ['present', 'array:url,summary,pr'],
                'payload.url' => ['sometimes', 'required', 'string', 'url:http,https', 'max:2048'],
                'payload.summary' => ['sometimes', 'string', 'max:'.WorkItem::LAST_PROGRESS_MAX_LENGTH],
                'payload.pr' => ['sometimes', 'required', 'array'],
                'payload.pr.repo' => ['required_with:payload.pr', 'string', 'min:1', 'max:255'],
                'payload.pr.number' => ['required_with:payload.pr', 'integer', 'min:1'],
                'payload.pr.url' => ['required_with:payload.pr', 'string', 'max:2048', 'url:https'],
            ])->validate();
            if (isset($data['payload']['pr'])) {
                $data['payload']['pr'] = WorkPullRequest::normalize($data['payload']['pr']);
            }
            if (($data['kind'] === WorkMilestoneKind::PullRequestOpened->value) !== isset($data['payload']['pr'])) {
                abort(422, 'PR metadata is allowed and required only for pr_opened.');
            }
            $kind = WorkMilestoneKind::from($data['kind']);
            $milestone = $kind === WorkMilestoneKind::Started
                ? $workers->started($item, $fence, $nonce, payload: $data['payload'])['milestone']
                : $workers->milestone($item, $fence, $kind, $data['payload'], nonce: $nonce);

            return response()->json(['id' => $milestone->id], 201);
        } catch (HttpException $exception) {
            return response($exception->getMessage(), $exception->getStatusCode(), ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
    }
}
