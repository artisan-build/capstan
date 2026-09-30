<?php

namespace App\Mcp;

use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

final class ToolResponse
{
    public const int PAYLOAD_BUDGET_BYTES = 400_000;

    /** @param array<string, mixed> $payload */
    public static function structured(array $payload): ResponseFactory
    {
        return Response::structured($payload);
    }

    /** @param array<string, mixed> $payload */
    public static function fits(array $payload): bool
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) && strlen($json) <= self::PAYLOAD_BUDGET_BYTES;
    }
}
