<?php

namespace App\Auth;

final readonly class AmpWorkloadIdentity
{
    public function __construct(
        public string $userId,
        public string $projectId,
        public string $threadId,
        public string $email,
    ) {}
}
