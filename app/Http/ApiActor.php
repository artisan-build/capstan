<?php

namespace App\Http;

final readonly class ApiActor
{
    /** @param 'user'|'ci'|'system' $type */
    private function __construct(
        public string $type,
        public ?int $userId,
        public ?int $teamId,
        public ?int $ciTokenId,
        public ?int $systemTokenId,
    ) {}

    public static function user(int $userId): self
    {
        return new self('user', $userId, null, null, null);
    }

    public function isUser(): bool
    {
        return $this->type === 'user';
    }

    public function isCi(): bool
    {
        return $this->type === 'ci';
    }

    public function isSystem(): bool
    {
        return $this->type === 'system';
    }
}
