<?php

namespace App\Models;

use App\Enums\DeviceCodeStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DeviceCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $device_code_hash
 * @property string $user_code
 * @property string|null $label
 * @property int|null $user_id
 * @property DeviceCodeStatus $status
 * @property int $interval
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $last_polled_at
 * @property-read User|null $user
 */
class DeviceCode extends Model
{
    /** @use HasFactory<DeviceCodeFactory> */
    use HasFactory;

    public const int LIFETIME_SECONDS = 600;

    public const int POLL_INTERVAL_SECONDS = 5;

    /** @var list<string> */
    protected $fillable = [
        'device_code_hash',
        'user_code',
        'label',
        'interval',
        'expires_at',
        'last_polled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DeviceCodeStatus::class,
            'interval' => 'integer',
            'expires_at' => 'datetime',
            'last_polled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{device_code: string, model: DeviceCode} */
    public static function issue(?string $label = null): array
    {
        $plaintext = Str::random(64);

        $model = static::query()->create([
            'device_code_hash' => self::hash($plaintext),
            'user_code' => self::generateUserCode(),
            'label' => $label,
            'interval' => self::POLL_INTERVAL_SECONDS,
            'expires_at' => now()->addSeconds(self::LIFETIME_SECONDS),
        ]);

        return ['device_code' => $plaintext, 'model' => $model];
    }

    public static function findByDeviceCode(string $plaintext): ?DeviceCode
    {
        return static::query()
            ->where('device_code_hash', self::hash($plaintext))
            ->first();
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public static function generateUserCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = collect(range(1, 8))
                ->map(fn (): string => $alphabet[random_int(0, mb_strlen($alphabet) - 1)])
                ->implode('');

            $code = mb_substr($code, 0, 4).'-'.mb_substr($code, 4, 4);
        } while (static::query()->where('user_code', $code)->exists());

        return $code;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->status === DeviceCodeStatus::Pending;
    }

    public function isDenied(): bool
    {
        return $this->status === DeviceCodeStatus::Denied;
    }
}
