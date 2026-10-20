<?php

namespace App\Machine;

use App\Models\Team;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class TeamMemberships
{
    /** @return Builder<Team> */
    public function teamsFor(User $user): Builder
    {
        return Team::query()->whereIn('id', DB::table('team_user')
            ->select('team_id')
            ->where('user_id', $user->getKey()));
    }

    public function belongsTo(User $user, int $teamId): bool
    {
        return DB::table('team_user')
            ->where('user_id', $user->getKey())
            ->where('team_id', $teamId)
            ->exists();
    }

    public function joinDefault(User $user): void
    {
        $team = Team::default();
        DB::table('team_user')->insertOrIgnore([
            'team_id' => $team->id,
            'user_id' => $user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
