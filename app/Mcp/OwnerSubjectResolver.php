<?php

namespace App\Mcp;

use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;
use Laravel\Mcp\Exceptions\JsonRpcException;

final class OwnerSubjectResolver
{
    public function __construct(private readonly Request $request) {}

    public function resolve(): OwnerSubject
    {
        $principal = $this->request->user();

        if (! $principal instanceof Credential
            || $principal->subject_type !== SubjectType::UserPrincipal
            || ! is_string($principal->subject_ref)
            || ! is_string($principal->user_id)
            || preg_match('/\A[1-9][0-9]{0,18}\z/D', $principal->user_id) !== 1
            || ! hash_equals('capstan-user:'.$principal->user_id, $principal->subject_ref)
            || ! User::query()->whereKey($principal->user_id)->exists()) {
            throw new JsonRpcException('This MCP principal is not supported.', -32001);
        }

        return new OwnerSubject(
            type: $principal->subject_type->value,
            ref: $principal->subject_ref,
            actorId: $principal->user_id,
        );
    }
}
