<?php

namespace App\Models;

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Support\Address;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use stdClass;

/**
 * @property string $id
 * @property MessageType $type
 * @property int $version
 * @property string $from_address
 * @property string $to_address
 * @property string $to_local_part
 * @property string $to_server_id
 * @property array<int, mixed>|stdClass $body
 * @property array<array-key, mixed> $refs
 * @property string $message_id
 * @property string $signature
 * @property MessageStatus $status
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $acked_at
 * @property CarbonImmutable|null $received_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Envelope extends Model
{
    public const int CURRENT_VERSION = 1;

    protected $table = 'messages';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var array<string, mixed> */
    protected $attributes = [
        'version' => self::CURRENT_VERSION,
        'refs' => '[]',
        'status' => MessageStatus::Pending->value,
    ];

    /** @var list<string> */
    protected $fillable = [
        'id',
        'type',
        'version',
        'from_address',
        'to_address',
        'body',
        'refs',
        'message_id',
        'signature',
        'status',
        'delivered_at',
        'acked_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'version' => 'integer',
            'body' => 'object',
            'refs' => 'array',
            'status' => MessageStatus::class,
            'delivered_at' => 'datetime',
            'acked_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Envelope $envelope): void {
            // Server-assigned delivery priority key; never taken from the wire.
            $envelope->received_at ??= now();
        });

        static::saving(function (Envelope $envelope): void {
            Address::parse($envelope->from_address);
            $to = Address::parse($envelope->to_address);

            if ($envelope->version === self::CURRENT_VERSION && $envelope->refs !== []) {
                throw new InvalidArgumentException('Envelope refs are reserved and must be empty in version 1.');
            }

            $envelope->to_local_part = $to->localPart;
            $envelope->to_server_id = $to->serverId;
        });
    }

    public function isVersionSupported(): bool
    {
        return $this->version === self::CURRENT_VERSION;
    }

    public function knownVersionForRejection(): ?int
    {
        return $this->isVersionSupported() ? null : self::CURRENT_VERSION;
    }

    /**
     * @return array{
     *     id: string,
     *     type: string,
     *     version: int,
     *     from: string,
     *     to: string,
     *     created_at: string,
     *     message_id: string,
     *     body: array<int, mixed>|stdClass,
     *     refs: list<mixed>
     * }
     */
    public function signablePayload(): array
    {
        $createdAt = $this->getAttribute('created_at');
        $body = $this->body;

        if (! $createdAt instanceof \DateTimeInterface) {
            throw new LogicException('An envelope must have a creation time before it can be signed.');
        }

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'version' => $this->version,
            'from' => $this->from_address,
            'to' => $this->to_address,
            'created_at' => CarbonImmutable::instance($createdAt)->utc()->format('Y-m-d\TH:i:s\Z'),
            'message_id' => $this->message_id,
            'body' => $body === [] ? new stdClass : $body,
            'refs' => array_values($this->refs),
        ];
    }
}
