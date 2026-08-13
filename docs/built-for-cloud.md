# Built for Cloud (D30)

Capstan is a **Built-for-Cloud (BfC) catalog product**. It requires
[`artisan-build/built-for-cloud`](https://github.com/artisan-build/built-for-cloud), which gives a
Laravel Cloud console a uniform way to identify, claim, and administer any app in the catalog.

The package **coexists with** Capstan's own Sanctum auth. Nothing about Capstan's existing login,
device-code/loopback/one-time-code flows, `ResolveApiActor` middleware, or artifact ingest changed.

## Two orthogonal token planes

| | Sanctum personal access tokens | BfC `api_tokens` |
| --- | --- | --- |
| Table | `personal_access_tokens` | `api_tokens` |
| Owned by | a `User` | nobody — ownerless machine credentials |
| Scopes | Sanctum abilities | `Scope` enum: `consume` / `admin` / `onboard` |
| Minted by | Capstan's loopback, device-code, and one-time-code flows | `php artisan token:*` (Cloud CLI) and the BfC onboarding endpoints |
| Resolved by | `App\Http\Middleware\ResolveApiActor` (alias `capstan.auth`) | the package's own `bfc.token.admin` middleware |
| Guards | Capstan's routes — artifact authorship and everything a human or their agent does | the package's own `/bfc/*` control-plane routes |

These planes do not overlap. Capstan's routes are **not** wired to accept BfC machine tokens, and the
`/bfc/*` routes are **not** wired to accept Sanctum PATs. Dual-resolution inside `ResolveApiActor`
(letting a machine token act on Capstan's own endpoints) is deliberately **deferred** — it needs an
answer for what a `User`-less actor means for artifact authorship first.

The package registers its routes itself: `GET /bfc/meta` (unauthenticated product metadata),
`/bfc/ownership/*`, and `/bfc/onboarding/*`. The credential API stays disabled by default.

## The two auth-foundation opt-outs

The package ships an optional "auth foundation" for apps that don't already have one. Capstan does,
so both pieces are opted out. Set these wherever Capstan runs — they are in `.env.example` and, so
the test and CI databases opt out too, in `phpunit.xml`:

```dotenv
BUILT_FOR_CLOUD_PRODUCT=Capstan
BUILT_FOR_CLOUD_INVITATIONS=false
BUILT_FOR_CLOUD_USER_ADMIN_COLUMN=false
```

- **`BUILT_FOR_CLOUD_INVITATIONS=false`** — Capstan owns `invitations` (`code`, `role`, `issued_by`,
  `used_by`, `expires_at`, plus `App\Models\Invitation` and the `IssueInvitation` action). The
  package's `invitations` migration is timestamped *earlier* than Capstan's, so leaving it enabled
  would create the package's shape first and make Capstan's `Schema::create` collide on a fresh
  migrate. Opting out makes that migration a no-op.
- **`BUILT_FOR_CLOUD_USER_ADMIN_COLUMN=false`** — Capstan's admin concept is `OrgRole`
  (`owner` / `admin` / `member`) on `users`. A separate `is_admin` boolean column would be a second,
  drift-prone source of truth.

In `phpunit.xml` these are written as the literal `(false)`, not `false`. PHPUnit casts a
`value="false"` attribute to a real boolean and then `putenv()`s it as an *empty string*, which is
not the `=== false` the package's migration guards check for. Laravel's `Env` repository maps
`(false)` to boolean `false`, so the literal round-trips correctly.

`tests/Feature/BuiltForCloud/AuthFoundationOptOutTest.php` locks this in: after a fresh migrate,
Capstan's `invitations` columns are present, the BfC ownership tables (`api_tokens`,
`ownership_claims`, `ownership`, `onboarding_tokens`) exist, and `users` has no `is_admin` column.

## `is_admin` is derived from `OrgRole`

The package's `bfc.admin` middleware (`EnsureUserIsAdmin`) reads `$user->getAttribute('is_admin')`.
`App\Models\User` satisfies that with a **read-only** accessor rather than a column:

```php
protected function isAdmin(): Attribute
{
    return Attribute::make(
        get: fn (): bool => $this->org_role === OrgRole::Owner || $this->org_role === OrgRole::Admin,
    );
}
```

There is no migration, no `$fillable` entry, and nothing persisted. Grant admin by setting
`org_role`.

The trade-off: Capstan does **not** use the package's `create-admin` command, because that write path
needs a real column to set. Admin in Capstan is always granted through the org-role UI or by setting
`org_role` directly.

## Contract conformance

`tests/Feature/BuiltForCloud/ContractTest.php` runs the package's shipped
`ArtisanBuild\BuiltForCloud\Testing\ContractAssertions::assertBuiltForCloudContract()` unmodified. It
covers `/bfc/meta`'s payload shape, the ownership and onboarding auth boundaries, and the
`api_tokens` model shape. None of it depends on the auth-foundation migrations, so the opt-outs cost
nothing in conformance.
