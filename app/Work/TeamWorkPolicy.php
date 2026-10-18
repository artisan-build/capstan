<?php

namespace App\Work;

use App\Models\Team;
use App\Models\TeamWorkPolicyChange;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The one writer of a team's Work policy, shared by the Work page and MCP so every change is gated
 * and recorded the same way.
 */
final readonly class TeamWorkPolicy
{
    public const string SURFACE_WEB = 'web';

    public const string SURFACE_MCP = 'mcp';

    /**
     * Pooled work only changes how tasks created from now on are addressed. Tasks already pinned to
     * their creator stay pinned.
     *
     * @return bool whether the stored value changed
     */
    public function setPoolWork(Team $team, User $actor, bool $poolWork, string $surface): bool
    {
        if (! RolePolicy::canManageMembers($actor->role)) {
            throw new AuthorizationException('Only an Owner or Admin can change pooled work.');
        }

        return DB::transaction(function () use ($team, $actor, $poolWork, $surface): bool {
            $locked = Team::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->pool_work === $poolWork) {
                $team->setRawAttributes($locked->getAttributes(), true);

                return false;
            }
            $old = $locked->pool_work;
            $locked->update(['pool_work' => $poolWork]);
            TeamWorkPolicyChange::query()->create([
                'team_id' => $locked->id,
                'actor' => (string) $actor->getKey(),
                'surface' => $surface,
                'old_pool_work' => $old,
                'new_pool_work' => $poolWork,
            ]);
            $team->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /** @return array{team_id: int, team_name: string, pool_work: bool} */
    public function present(Team $team): array
    {
        return ['team_id' => $team->id, 'team_name' => $team->name, 'pool_work' => $team->pool_work];
    }
}
