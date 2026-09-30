<?php

namespace App\Mcp\Tools;

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
                'arguments' => ['Unknown argument: '.$unexpected[0].'.'],
            ]);
        }

        if (array_keys($request->all()) !== array_keys(get_object_vars($arguments))) {
            throw new JsonRpcException('Tool arguments could not be decoded safely.', -32602);
        }
    }

    private function rawArgumentObject(): ?stdClass
    {
        $payload = json_decode(request()->getContent(), false);
        $params = $payload instanceof stdClass && property_exists($payload, 'params') ? $payload->params : null;
        $arguments = $params instanceof stdClass && property_exists($params, 'arguments') ? $params->arguments : null;

        return $arguments instanceof stdClass ? $arguments : null;
    }
}
