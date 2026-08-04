<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AuthorizationCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $code_hash
 * @property int|null $user_id
 * @property string|null $label
 * @property string $redirect_uri
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property-read User|null $user
 */
class AuthorizationCode extends Model
{
    /** @use HasFactory<AuthorizationCodeFactory> */
    use HasFactory;

    public const int LIFETIME_SECONDS = 120;

    protected $table = 'cli_authorization_codes';

    /** @var list<string> */
    protected $fillable = [
        'code_hash',
        'user_id',
        'label',
        'redirect_uri',
        'expires_at',
        'consumed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{code: string, model: AuthorizationCode} */
    public static function issue(User $user, string $redirectUri, ?string $label = null): array
    {
        $plaintext = Str::random(64);

        $model = static::query()->create([
            'code_hash' => self::hash($plaintext),
            'user_id' => $user->id,
            'label' => $label,
            'redirect_uri' => $redirectUri,
            'expires_at' => now()->addSeconds(self::LIFETIME_SECONDS),
        ]);

        return ['code' => $plaintext, 'model' => $model];
    }

    public static function findByCode(string $plaintext): ?AuthorizationCode
    {
        return static::query()
            ->where('code_hash', self::hash($plaintext))
            ->first();
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function consume(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]) === 1;
    }
}
