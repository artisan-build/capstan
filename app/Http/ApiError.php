<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;

final class ApiError
{
    /** @param array<string, mixed> $context */
    public static function response(int $status, string $code, string $message, array $context = []): JsonResponse
    {
        return new JsonResponse([
            'error' => array_merge([
                'code' => $code,
                'message' => $message,
            ], $context),
        ], $status);
    }

    public static function unauthenticated(string $message = 'Unauthenticated.'): JsonResponse
    {
        return self::response(401, 'unauthenticated', $message);
    }

    public static function forbidden(string $message = 'This action is not permitted.'): JsonResponse
    {
        return self::response(403, 'forbidden', $message);
    }

    public static function notFound(string $message = 'Not found.'): JsonResponse
    {
        return self::response(404, 'not_found', $message);
    }
}
