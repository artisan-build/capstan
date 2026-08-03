<?php

namespace Tests\Feature;

use App\Actions\ChangeOrgRole;
use App\Actions\RegisterFirstOwner;
use App\Actions\RegisterInvitedUser;
use App\Enums\OrgRole;
use App\Models\Invitation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamsTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_migrate_and_seed_has_exactly_one_default_team(): void
    {
        $this->seed();

        $this->assertSame(1, Team::query()->where('is_default', true)->count());
        $this->assertSame(1, Team::query()->where('slug', Team::DEFAULT_SLUG)->count());
    }

    public function test_first_owner_registration_adds_user_to_default_team(): void
    {
        $user = app(RegisterFirstOwner::class)->handle([
            'name' => 'First Owner',
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);

        $this->assertTrue($user->refresh()->teams()->whereKey(Team::default()->id)->exists());
    }

    public function test_invited_registration_adds_user_to_default_team(): void
    {
        $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
        $invitation = Invitation::factory()->create(['issued_by' => $owner->id]);

        $user = app(RegisterInvitedUser::class)->handle([
            'name' => 'Invited User',
            'email' => 'invited@example.com',
            'password' => 'password',
            'invitation_code' => $invitation->code,
        ]);

        $this->assertTrue($user->refresh()->teams()->whereKey(Team::default()->id)->exists());
    }

    public function test_org_role_and_team_membership_are_independent(): void
    {
        $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
        $user = User::factory()->create(['org_role' => OrgRole::Member]);
        $team = Team::query()->create(['name' => 'Private Team', 'slug' => 'private-team']);

        $originalTeamIds = $user->teams()->pluck('teams.id')->all();

        app(ChangeOrgRole::class)->handle($owner, $user, OrgRole::Admin);

        $this->assertSame($originalTeamIds, $user->refresh()->teams()->pluck('teams.id')->all());

        $user->teams()->syncWithoutDetaching([$team->id]);

        $this->assertSame(OrgRole::Admin, $user->refresh()->org_role);
        $this->assertTrue($user->teams()->whereKey($team->id)->exists());
    }

    public function test_default_team_is_not_duplicated_by_repeated_seeding_or_registration(): void
    {
        app(RegisterFirstOwner::class)->handle([
            'name' => 'First Owner',
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);

        $owner = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $invitation = Invitation::factory()->create(['issued_by' => $owner->id]);

        app(RegisterInvitedUser::class)->handle([
            'name' => 'Invited User',
            'email' => 'invited@example.com',
            'password' => 'password',
            'invitation_code' => $invitation->code,
        ]);

        $this->seed();
        $this->seed();

        $this->assertSame(1, Team::query()->where('is_default', true)->count());
        $this->assertSame(1, Team::query()->where('slug', Team::DEFAULT_SLUG)->count());
    }
}
