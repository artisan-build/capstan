<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\OrgRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property OrgRole $org_role
 * @property-read bool $is_admin
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            Team::default()->users()->syncWithoutDetaching([$user->id]);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'org_role' => OrgRole::class,
            'password' => 'hashed',
        ];
    }

    /**
     * Derived, read-only admin flag for Built for Cloud (D30).
     *
     * The package's `bfc.admin` middleware (EnsureUserIsAdmin) reads
     * `getAttribute('is_admin')`. Capstan's admin concept is OrgRole, so this
     * accessor projects that onto the name BfC expects instead of adding a
     * second source of truth. There is deliberately NO `is_admin` column, no
     * migration, and no entry in $fillable.
     *
     * Consequence: Capstan does NOT use BfC's `create-admin` command — that
     * write path needs a real column. Grant admin by setting `org_role`.
     *
     * @return Attribute<bool, never>
     */
    protected function isAdmin(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->org_role === OrgRole::Owner || $this->org_role === OrgRole::Admin,
        );
    }

    public function canIssueInvitations(): bool
    {
        return in_array($this->org_role, [OrgRole::Owner, OrgRole::Admin], true);
    }

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withTimestamps();
    }

    /**
     * @return HasMany<Artifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class, 'author_id');
    }

    /**
     * @return HasMany<Spoke, $this>
     */
    public function spokes(): HasMany
    {
        return $this->hasMany(Spoke::class);
    }

    public function canChangeOrgRoleTo(User $target, OrgRole $role): bool
    {
        if ($this->org_role === OrgRole::Owner) {
            return true;
        }

        if ($this->org_role !== OrgRole::Admin) {
            return false;
        }

        return $target->org_role !== OrgRole::Owner && $role !== OrgRole::Owner;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
