<?php

namespace App\Postmaster;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class PendingInstallLinks
{
    public function __construct(private OnboardingSnippet $snippet) {}

    public function issue(Request $request, string $actorId): PendingInstallLink
    {
        return DB::transaction(function () use ($request, $actorId): PendingInstallLink {
            $generated = $this->snippet->generateWithAuthorization($request, $actorId);
            $expiresAt = DB::table('credential_authorizations')
                ->where('id', $generated->authorizationId)
                ->value('expires_at');

            if (! is_string($expiresAt)) {
                throw new RuntimeException('The device authorization expiry could not be read.');
            }

            $now = now();
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

            DB::table('postmaster_installs')
                ->where('actor_id', $actorId)
                ->whereNull('used_at')
                ->update([
                    'used_at' => $now,
                    'updated_at' => $now,
                ]);

            DB::table('postmaster_installs')->insert([
                'id' => (string) Str::uuid(),
                'actor_id' => $actorId,
                'token_hash' => hash('sha256', $token),
                'script' => $generated->script,
                'expires_at' => $expiresAt,
                'used_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $url = $this->absoluteInstallUrl($token);

            return new PendingInstallLink(
                command: 'curl -fsSL '.escapeshellarg($url).' | sh',
                script: $generated->script,
                expiresAt: CarbonImmutable::parse($expiresAt),
            );
        });
    }

    public function consume(string $token): ?string
    {
        return DB::transaction(function () use ($token): ?string {
            $install = DB::table('postmaster_installs')
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($install === null
                || $install->used_at !== null
                || CarbonImmutable::parse((string) $install->expires_at)->lessThanOrEqualTo(now())) {
                return null;
            }

            DB::table('postmaster_installs')
                ->where('id', $install->id)
                ->update([
                    'used_at' => now(),
                    'updated_at' => now(),
                ]);

            return (string) $install->script;
        });
    }

    private function absoluteInstallUrl(string $token): string
    {
        $baseUrl = config('app.url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException('APP_URL must be configured before generating a Postmaster installer.');
        }

        return rtrim($baseUrl, '/').'/'.ltrim(route('postmaster.install', ['token' => $token], false), '/');
    }
}
