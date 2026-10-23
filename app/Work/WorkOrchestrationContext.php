<?php

namespace App\Work;

final readonly class WorkOrchestrationContext
{
    public function __construct(
        public string $parentTaskId,
        public string $actorId,
        public int $spokeId,
        public int $seatFence,
        public ?string $sessionRef,
        public ?string $turnItemId = null,
        public ?int $itemFence = null,
        public ?string $launchNonce = null,
    ) {}

    public function isInteractive(): bool
    {
        return $this->turnItemId === null;
    }
}
