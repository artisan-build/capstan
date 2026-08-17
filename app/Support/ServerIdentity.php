<?php

namespace App\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ServerIdentity
{
    private const string SERVER_ID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    private ?string $resolvedId = null;

    public function id(): string
    {
        if ($this->resolvedId !== null) {
            return $this->resolvedId;
        }

        $configured = config('capstan.postmaster.server_id');

        if (is_string($configured) && $configured !== '') {
            if (! self::isValidId($configured)) {
                throw new InvalidArgumentException('The configured Capstan server id is malformed.');
            }

            return $this->resolvedId = $configured;
        }

        $stored = $this->existingId();

        if ($stored !== null) {
            return $this->resolvedId = $stored;
        }

        $candidate = (string) Str::ulid();
        $now = now();

        try {
            DB::table('server_identity')->insert([
                'id' => 1,
                'server_id' => $candidate,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->resolvedId = $candidate;
        } catch (UniqueConstraintViolationException $exception) {
            $stored = $this->existingId();

            if ($stored === null) {
                throw $exception;
            }

            return $this->resolvedId = $stored;
        }
    }

    public static function isValidId(string $serverId): bool
    {
        return preg_match(self::SERVER_ID_PATTERN, $serverId) === 1;
    }

    protected function existingId(): ?string
    {
        $serverId = DB::table('server_identity')->where('id', 1)->value('server_id');

        if ($serverId === null) {
            return null;
        }

        if (! is_string($serverId) || ! self::isValidId($serverId)) {
            throw new RuntimeException('The stored Capstan server id is malformed.');
        }

        return $serverId;
    }
}
