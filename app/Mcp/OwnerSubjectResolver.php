<?php

namespace App\Mcp;

use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Console\RequestAssertion;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\Exceptions\ManagedAuthRefused;
use ArtisanBuild\BuiltForCloud\ManagedAuthConnection;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

final readonly class OwnerSubjectResolver
{
    public function __construct(private Request $request) {}

    public function resolve(): OwnerSubject
    {
        $actingPrincipal = RequestAssertion::principal($this->request);

        if ($actingPrincipal?->delegatedActor instanceof DelegatedActor) {
            return $this->delegatedOwner($actingPrincipal->delegatedActor)
                ?? throw new UnsupportedMcpPrincipal;
        }

        $principal = ($this->request->getUserResolver())();

        $owner = $principal instanceof Credential
            ? $this->credentialOwner($principal)
            : null;

        return $owner ?? throw new UnsupportedMcpPrincipal;
    }

    private function credentialOwner(Credential $principal): ?OwnerSubject
    {
        if ($principal->subject_type !== SubjectType::UserPrincipal
            || ! is_string($principal->user_id)
            || preg_match('/\A[1-9][0-9]{0,18}\z/D', $principal->user_id) !== 1
            || ! hash_equals('capstan-user:'.$principal->user_id, $principal->subject_ref)
            || ! User::query()->whereKey($principal->user_id)->exists()) {
            return null;
        }

        return new OwnerSubject(
            type: $principal->subject_type->value,
            ref: $principal->subject_ref,
            actorId: $principal->user_id,
            credentialId: (string) $principal->getKey(),
        );
    }

    private function delegatedOwner(DelegatedActor $principal): ?OwnerSubject
    {
        try {
            $connection = ManagedAuthConnection::current();
        } catch (ManagedAuthRefused) {
            return null;
        }

        if (! hash_equals($connection->issuer, $principal->issuer)) {
            return null;
        }

        $user = User::query()
            ->where('scalpels_issuer', $connection->issuer)
            ->where('scalpels_connection_id', $connection->connectionId)
            ->where('scalpels_id', $principal->subject)
            ->first();

        if (! $user instanceof User) {
            return null;
        }

        $actorId = (string) $user->getAuthIdentifier();

        return new OwnerSubject(
            type: SubjectType::UserPrincipal->value,
            ref: 'capstan-user:'.$actorId,
            actorId: $actorId,
            credentialId: (string) Uuid::uuid5(
                Uuid::NAMESPACE_URL,
                DelegatedActor::class."\0".$principal->identity_hash,
            ),
        );
    }
}
