<?php

namespace App\Support;

use App\Models\Envelope;
use RuntimeException;

final class EnvelopeSigner
{
    public function sign(Envelope $envelope): string
    {
        return hash_hmac(
            'sha256',
            JsonCanonicalizer::encode($envelope->signablePayload()),
            $this->key(),
        );
    }

    public function verify(Envelope $envelope): bool
    {
        $signature = $envelope->getAttribute('signature');

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            JsonCanonicalizer::encode($envelope->signablePayload()),
            $this->key(),
        );

        return hash_equals($expected, $signature);
    }

    private function key(): string
    {
        PostmasterClock::assertUtc();

        $key = config('capstan.postmaster.signing_key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('A postmaster signing key is required.');
        }

        return $key;
    }
}
