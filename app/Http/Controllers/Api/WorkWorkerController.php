<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkMilestoneKind;
use App\Http\Controllers\Controller;
use App\Models\WorkItem;
use App\Work\WorkWorkerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class WorkWorkerController extends Controller
{
    public function __invoke(Request $request, string $item, WorkWorkerService $workers): Response
    {
        abort_unless($request->hasValidSignature(), 403);
        $fence = (int) $request->query('fence');

        try {
            if ($request->routeIs('api.work.worker.brief')) {
                $brief = $workers->brief($item, $fence);
                if ($request->prefers(['application/json', 'text/markdown']) === 'text/markdown') {
                    return response('# '.$brief['task']['title']."\n\nRole: ".$brief['item']['role']."\n\n".($brief['revision']['content'] ?? ''), 200, ['Content-Type' => 'text/markdown; charset=UTF-8']);
                }

                return response()->json($brief);
            }
            if ($request->routeIs('api.work.worker.steers')) {
                return response()->json(['steers' => $workers->steers($item, $fence)]);
            }

            $object = json_decode($request->getContent());
            abort_unless($object instanceof \stdClass && isset($object->payload) && $object->payload instanceof \stdClass, 422, 'Expected an object with a payload object.');
            abort_if(array_diff(array_keys((array) $object), ['kind', 'payload']) !== [], 422, 'Unknown keys.');
            abort_if(strlen(json_encode($object->payload, JSON_THROW_ON_ERROR)) > 16384, 422, 'Payload too large.');
            $data = Validator::make(['kind' => $object->kind ?? null, 'payload' => (array) $object->payload], [
                'kind' => ['required', Rule::enum(WorkMilestoneKind::class)],
                'payload' => ['present', 'array:url,summary'],
                'payload.url' => ['sometimes', 'required', 'string', 'url:http,https', 'max:2048'],
                'payload.summary' => ['sometimes', 'string', 'max:'.WorkItem::LAST_PROGRESS_MAX_LENGTH],
            ])->validate();
            $milestone = $workers->milestone($item, $fence, WorkMilestoneKind::from($data['kind']), $data['payload']);

            return response()->json(['id' => $milestone->id], 201);
        } catch (HttpException $exception) {
            return response($exception->getMessage(), $exception->getStatusCode(), ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
    }
}
