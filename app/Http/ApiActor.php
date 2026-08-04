<?php

namespace App\Http;

final readonly class ApiActor
{
    // Additional actor types arrive with machine tokens.
    private function __construct(
        public string $type,
        public ?int $userId,
    ) {}

    public static function user(int $userId): self
    {
        return new self('user', $userId);
    }

    public function isUser(): bool
    {
        return $this->type === 'user';
    }
}
