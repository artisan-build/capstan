<?php

namespace App\Mcp;

final readonly class OwnerSubject
{
    public function __construct(
        public string $type,
        public string $ref,
        public string $actorId,
    ) {}
}
