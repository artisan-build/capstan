<?php

namespace App\Models;

use App\Enums\PageShareKind;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $page_id
 * @property string $created_by_user_id
 * @property PageShareKind $kind
 * @property string|null $internal_recipient_user_id
 * @property string|null $recipient_email
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Page $page
 * @property-read User|null $internalRecipient
 *
 * @mixin IdeHelperPageShare
 */
class PageShare extends Model
{
    use HasUuids;

    protected $fillable = [
        'page_id',
        'created_by_user_id',
        'kind',
        'internal_recipient_user_id',
        'recipient_email',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PageShareKind::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** @return BelongsTo<User, $this> */
    public function internalRecipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'internal_recipient_user_id');
    }

    /** @param Builder<PageShare> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
