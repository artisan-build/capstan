<?php

namespace App\Http;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An ApiError raised from inside a unit of work (typically a DB transaction) so the
 * work rolls back and the caller answers with the standard error envelope.
 */
final class ApiErrorException extends RuntimeException implements Responsable
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function toResponse($request): JsonResponse
    {
        return ApiError::response($this->status, $this->errorCode, $this->getMessage(), $this->context);
    }
}
