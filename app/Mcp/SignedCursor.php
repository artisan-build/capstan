<?php

namespace App\Mcp;

use JsonException;
use Laravel\Mcp\Exceptions\JsonRpcException;

final class SignedCursor
{
    /** @param array<string, mixed> $payload */
    public function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $encoded = $this->base64UrlEncode($json);

        return $encoded.'.'.$this->base64UrlEncode(hash_hmac('sha256', $encoded, $this->key(), true));
    }

    /** @return array<string, mixed> */
    public function decode(string $cursor, string $actorBinding, string $queryBinding): array
    {
        $parts = explode('.', $cursor);

        if (count($parts) !== 2 || strlen($cursor) > 2048) {
            throw $this->invalid();
        }

        [$encoded, $mac] = $parts;
        $decodedMac = $this->base64UrlDecode($mac);

        if ($decodedMac === null || ! hash_equals(hash_hmac('sha256', $encoded, $this->key(), true), $decodedMac)) {
            throw $this->invalid();
        }

        $json = $this->base64UrlDecode($encoded);

        try {
            $payload = $json === null ? null : json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalid();
        }

        if (! is_array($payload)
            || array_is_list($payload)
            || ($payload['actor'] ?? null) !== $actorBinding
            || ($payload['query'] ?? null) !== $queryBinding) {
            throw $this->invalid();
        }

        return $payload;
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:')
            ? (base64_decode(substr($key, 7), true) ?: $key)
            : $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private function invalid(): JsonRpcException
    {
        return new JsonRpcException('The cursor is invalid for this request.', -32602);
    }
}
