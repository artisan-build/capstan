<?php

namespace App\Postmaster;

final readonly class GeneratedOnboardingSnippet
{
    /**
     * @param array{
     *     team_id: int,
     *     enabled: true,
     *     roles: list<string>,
     *     capabilities: list<string>,
     *     role_providers: array<string, string>,
     *     concurrency_ceiling: 1
     * } $initialProfile
     */
    public function __construct(
        public string $script,
        public string $authorizationId,
        public array $initialProfile,
    ) {}
}
