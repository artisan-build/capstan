<?php

namespace App\Mcp\Tools;

use App\Mcp\OwnerSubject;
use App\Mcp\OwnerSubjectResolver;
use App\Support\BrandKitService;
use ArtisanBuild\BuiltForCloud\User;
use Laravel\Mcp\Exceptions\JsonRpcException;

abstract class BrandTool extends CapstanTool
{
    /** @return array{OwnerSubject, User} */
    protected function brandActor(OwnerSubjectResolver $owners, BrandKitService $brands, bool $manage): array
    {
        $owner = $owners->resolve();
        $actor = User::query()->find($owner->actorId);
        if (! $actor instanceof User) {
            throw new JsonRpcException('This MCP principal is not supported.', -32001);
        }

        if ($manage) {
            $brands->assertCanManage($actor);
        } else {
            $brands->assertCanRead($actor);
        }

        return [$owner, $actor];
    }
}
