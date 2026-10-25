<?php

namespace App\Work;

use InvalidArgumentException;
use JsonException;

final readonly class AmpSessionReference
{
    public const string PREFIX = 'amp:v1:';

    public const int MAX_LENGTH = 4096;

    private function __construct(
        public string $projectId,
        public string $threadId,
        public string $threadUrl,
    ) {}

    public static function make(string $projectId, string $threadId, string $threadUrl): self
    {
        return self::fromValues($projectId, $threadId, $threadUrl);
    }

    public static function parse(string $reference): self
    {
        if (strlen($reference) > self::MAX_LENGTH || ! str_starts_with($reference, self::PREFIX)) {
            throw new InvalidArgumentException('Invalid Amp session reference.');
        }

        $encoded = substr($reference, strlen(self::PREFIX));
        if ($encoded === '' || preg_match('/\A[A-Za-z0-9_-]+\z/D', $encoded) !== 1) {
            throw new InvalidArgumentException('Invalid Amp session reference.');
        }

        $json = self::decodeBase64Url($encoded);

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid Amp session reference.', previous: $exception);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('Invalid Amp session reference.');
        }

        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== ['project_id', 'thread_id', 'thread_url']) {
            throw new InvalidArgumentException('Invalid Amp session reference.');
        }

        $parsed = self::fromValues($payload['project_id'], $payload['thread_id'], $payload['thread_url']);
        if (! hash_equals($parsed->encode(), $reference)) {
            throw new InvalidArgumentException('Invalid Amp session reference.');
        }

        return $parsed;
    }

    public function encode(): string
    {
        $json = json_encode([
            'project_id' => $this->projectId,
            'thread_id' => $this->threadId,
            'thread_url' => $this->threadUrl,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return self::PREFIX.rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private static function fromValues(mixed $projectId, mixed $threadId, mixed $threadUrl): self
    {
        if (! is_string($projectId) || ! is_string($threadId) || ! is_string($threadUrl)
            || $projectId === '' || $threadId === '' || $threadUrl === ''
            || $projectId !== trim($projectId) || $threadId !== trim($threadId)
            || strlen($projectId) > 255 || strlen($threadId) > 255 || strlen($threadUrl) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $projectId.$threadId.$threadUrl) === 1) {
            throw new InvalidArgumentException('Invalid Amp session reference.');
        }

        $parts = parse_url($threadUrl);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'ampcode.com'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || ! isset($parts['path'])) {
            throw new InvalidArgumentException('Invalid Amp session reference.');
        }

        $segments = array_map('rawurldecode', explode('/', trim($parts['path'], '/')));
        if (! in_array($threadId, $segments, true)) {
            throw new InvalidArgumentException('Invalid Amp session reference.');
        }

        return new self($projectId, $threadId, $threadUrl);
    }

    private static function decodeBase64Url(string $encoded): string
    {
        $padding = (4 - strlen($encoded) % 4) % 4;
        $decoded = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', $padding), true);
        if (! is_string($decoded)
            || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $encoded) {
            throw new InvalidArgumentException('Invalid Amp session reference.');
        }

        return $decoded;
    }
}
