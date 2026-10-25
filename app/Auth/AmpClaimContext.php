<?php

namespace App\Auth;

final readonly class AmpClaimContext
{
    public const string REQUEST_ATTRIBUTE = 'capstan.amp_claim';

    public function __construct(
        public AmpWorkloadIdentity $identity,
        public string $itemId,
        public int $fence,
        public string $launchNonce,
        public int $userId,
    ) {}

    public static function current(): self
    {
        $context = request()->attributes->get(self::REQUEST_ATTRIBUTE);
        abort_unless($context instanceof self, 401);

        return $context;
    }
}
