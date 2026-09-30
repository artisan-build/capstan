<?php

namespace App\Mcp;

use ArtisanBuild\BuiltForCloud\Http\Middleware\AuthenticateMcp;
use JsonException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

final class ToolResponse
{
    public const int PAYLOAD_BUDGET_BYTES = 400_000;

    public const int RESPONSE_BUDGET_BYTES = 1_048_576;

    /** @param array<string, mixed> $payload */
    public static function structured(array $payload): ResponseFactory
    {
        return Response::structured($payload);
    }

    /** @param array<string, mixed> $payload */
    public static function fits(array $payload): bool
    {
        try {
            $structured = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            if (strlen($structured) > self::PAYLOAD_BUDGET_BYTES) {
                return false;
            }

            $response = json_encode([
                'jsonrpc' => '2.0',
                'id' => str_repeat('i', AuthenticateMcp::MAX_JSON_RPC_ID_BYTES - 2),
                'result' => [
                    'content' => [['type' => 'text', 'text' => $structured]],
                    'isError' => false,
                    'structuredContent' => $payload,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return false;
        }

        return strlen($response) < self::RESPONSE_BUDGET_BYTES;
    }
}
