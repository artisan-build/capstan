<?php

namespace App\Auth;

use App\Support\ServerIdentity;
use ArtisanBuild\BuiltForCloud\BoundCredentialScope;
use ArtisanBuild\BuiltForCloud\Contracts\CredentialDeclaration;
use ArtisanBuild\BuiltForCloud\Contracts\DeclaresCredentialAuthorizationProfiles;
use ArtisanBuild\BuiltForCloud\Contracts\DeclaresSelfServiceMintPolicy;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialAuthorizationOwnership;
use ArtisanBuild\BuiltForCloud\CredentialAuthorizationProfile;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\DomainIdentityContext;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;

final readonly class CapstanCredentialDeclaration implements CredentialDeclaration, DeclaresCredentialAuthorizationProfiles, DeclaresSelfServiceMintPolicy
{
    public const string ACTOR_HEADER = 'X-Capstan-Actor-ID';

    public const string ARTIFACT_INGEST = 'capstan.artifact.ingest';

    public const string POSTMASTER_POLL = 'capstan.postmaster.poll';

    public function __construct(private ServerIdentity $installation) {}

    public function resolveSubject(Request $request): ?Subject
    {
        $actorId = $this->actorId($request);

        return $actorId === null ? null : $this->subject($actorId);
    }

    public function authorize(Credential $credential, ?string $ability, Request $request): bool
    {
        if (! $request->is('api/v1/artifacts', 'api/v1/poll')) {
            return true;
        }

        $actorId = $this->actorId($request, sessionAllowed: false);

        if ($actorId === null || $credential->user_id === null || ! hash_equals($actorId, $credential->user_id)) {
            return false;
        }

        $user = User::query()->find($actorId);

        return $user instanceof User
            && DomainIdentityContext::forUser($user, InstallationAuthority::current())->canUseProduct();
    }

    public function credentialAuthorizationProfiles(Request $request): array
    {
        $subject = $this->resolveSubject($request) ?? $this->subject('missing');
        $installation = $this->installation->id();

        return [
            $this->profile(self::ARTIFACT_INGEST, $subject, $installation, '/api/v1/artifacts'),
            $this->profile(self::POSTMASTER_POLL, $subject, $installation, '/api/v1/poll'),
        ];
    }

    public function selfServiceAbilities(Subject $subject): array
    {
        return [];
    }

    public function selfServiceKinds(Subject $subject): array
    {
        return [CredentialKind::Bearer];
    }

    private function profile(string $appPurpose, Subject $subject, string $installation, string $audience): CredentialAuthorizationProfile
    {
        return new CredentialAuthorizationProfile(
            appPurpose: $appPurpose,
            scope: new BoundCredentialScope(
                appPurpose: $appPurpose,
                subject: $subject,
                installation: $installation,
                application: 'capstan',
                audience: $audience,
            ),
            ownership: CredentialAuthorizationOwnership::Personal,
            abilities: [],
            expiresAt: null,
            codeTtlSeconds: 600,
            initialPollInterval: 5,
        );
    }

    private function actorId(Request $request, bool $sessionAllowed = true): ?string
    {
        if ($sessionAllowed) {
            $user = $request->user();

            if ($user instanceof User) {
                return (string) $user->getKey();
            }
        }

        $actorId = $request->header(self::ACTOR_HEADER);

        return is_string($actorId) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $actorId) === 1
            ? $actorId
            : null;
    }

    private function subject(string $actorId): Subject
    {
        return new Subject(SubjectType::UserPrincipal, 'capstan-user:'.$actorId);
    }
}
