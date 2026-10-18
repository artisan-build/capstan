<?php

namespace App\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;
use stdClass;

abstract class CapstanTool extends Tool
{
    use AdvertisesToolEffect {
        toArray as private advertisedToArray;
    }
    use RespectsEffectCeiling;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tool = $this->advertisedToArray();
        $tool['inputSchema']['additionalProperties'] = false;

        if (isset($tool['outputSchema'])) {
            $tool['outputSchema']['additionalProperties'] = false;
        }

        return $tool;
    }

    /** @param list<string> $allowed */
    protected function requireExactArguments(Request $request, array $allowed): void
    {
        $arguments = $this->rawArgumentObject();

        if (! $arguments instanceof stdClass) {
            throw new JsonRpcException('Tool arguments must be a JSON object.', -32602);
        }

        $unexpected = array_values(array_diff(array_keys(get_object_vars($arguments)), $allowed));

        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'arguments' => ['Unknown tool argument.'],
            ]);
        }

        if (array_keys($request->all()) !== array_keys(get_object_vars($arguments))) {
            throw new JsonRpcException('Tool arguments could not be decoded safely.', -32602);
        }
    }

    protected function rawObjectArgument(string $name): ?stdClass
    {
        $arguments = $this->rawArgumentObject();
        $value = $arguments instanceof stdClass && property_exists($arguments, $name)
            ? $arguments->{$name}
            : null;

        return $value instanceof stdClass ? $value : null;
    }

    protected function rawArgument(string $name): mixed
    {
        $arguments = $this->rawArgumentObject();

        return $arguments instanceof stdClass && property_exists($arguments, $name)
            ? $arguments->{$name}
            : null;
    }

    private function rawArgumentObject(): ?stdClass
    {
        $httpRequest = request();
        $payload = json_decode($httpRequest->getContent(), false);
        $params = $payload instanceof stdClass && property_exists($payload, 'params') ? $payload->params : null;
        if ($params instanceof stdClass) {
            $arguments = property_exists($params, 'arguments') ? $params->arguments : new stdClass;

            return $arguments instanceof stdClass ? $arguments : null;
        }

        if (! is_callable($httpRequest->attributes->get(ActingPrincipalResolver::OBSERVER_ATTRIBUTE))) {
            return null;
        }

        $mcpRequest = resolve('mcp.request');
        if (! $mcpRequest instanceof Request) {
            return null;
        }

        return json_decode(json_encode((object) $mcpRequest->all(), JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR);
    }
}
