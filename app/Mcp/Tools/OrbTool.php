<?php

namespace App\Mcp\Tools;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

abstract class OrbTool extends Tool
{
    /** @param list<string> $allowed */
    protected function requireExactArguments(Request $request, array $allowed): void
    {
        $payload = json_decode(request()->getContent());
        $params = $payload instanceof stdClass && property_exists($payload, 'params') ? $payload->params : null;
        $arguments = $params instanceof stdClass && property_exists($params, 'arguments') ? $params->arguments : new stdClass;
        if (! $arguments instanceof stdClass) {
            throw new JsonRpcException('Tool arguments must be a JSON object.', -32602);
        }

        $keys = array_keys(get_object_vars($arguments));
        if (array_diff($keys, $allowed) !== [] || $keys !== array_keys($request->all())) {
            throw ValidationException::withMessages(['arguments' => ['Unknown or invalid tool argument.']]);
        }
    }

    /** @param callable(): array<string, mixed> $operation */
    protected function forCurrentClaim(callable $operation): Response|ResponseFactory
    {
        try {
            return Response::structured($operation());
        } catch (AuthorizationException) {
            return Response::error('Work claim is unavailable.');
        } catch (HttpExceptionInterface $exception) {
            if (in_array($exception->getStatusCode(), [401, 403, 409, 410], true)) {
                return Response::error('Work claim is unavailable.');
            }

            throw $exception;
        }
    }
}
