<?php

namespace App\Mcp\Tools;

use App\Mcp\OwnerSubject;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\UnsupportedMcpPrincipal;
use App\Support\BrandKitService;
use ArtisanBuild\BuiltForCloud\User;

abstract class BrandTool extends CapstanTool
{
    /** @return array{OwnerSubject, User} */
    protected function brandActor(OwnerSubjectResolver $owners, BrandKitService $brands, bool $manage): array
    {
        $owner = $owners->resolve();
        $actor = User::query()->find($owner->actorId);
        if (! $actor instanceof User) {
            throw new UnsupportedMcpPrincipal;
        }

        if ($manage) {
            $brands->assertCanManage($actor);
        } else {
            $brands->assertCanRead($actor);
        }

        return [$owner, $actor];
    }
}
