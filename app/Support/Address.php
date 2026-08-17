<?php

namespace App\Support;

use InvalidArgumentException;

final readonly class Address
{
    private const string LOCAL_PART_PATTERN = '/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?\z/';

    private function __construct(
        public string $localPart,
        public string $serverId,
    ) {}

    public static function parse(string $address): self
    {
        $parts = explode('@', $address);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException('A postmaster address must contain exactly one @ separator.');
        }

        return self::make($parts[0], $parts[1]);
    }

    public static function make(string $localPart, string $serverId): self
    {
        if (! self::isValidLocalPart($localPart)) {
            throw new InvalidArgumentException('The postmaster address local part is malformed.');
        }

        if (! ServerIdentity::isValidId($serverId)) {
            throw new InvalidArgumentException('The postmaster address server id is malformed.');
        }

        return new self($localPart, $serverId);
    }

    public static function isValidLocalPart(string $localPart): bool
    {
        return strlen($localPart) <= 64 && preg_match(self::LOCAL_PART_PATTERN, $localPart) === 1;
    }

    public function format(): string
    {
        return $this->localPart.'@'.$this->serverId;
    }

    public function __toString(): string
    {
        return $this->format();
    }

    public function isLocal(string $serverId): bool
    {
        return $this->serverId === $serverId;
    }
}
