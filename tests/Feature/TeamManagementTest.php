<?php

namespace Tests\Feature;

use App\Actions\ChangeOrgRole;
use App\Actions\EnsureAnotherOwnerRemains;
use App\Actions\RemoveMember;
use App\Enums\OrgRole;
use App\Models\Artifact;
use App\Models\Invitation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_page_access_is_limited_to_owners_and_admins(): void
    {
        $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
        $admin = User::factory()->create(['org_role' => OrgRole::Admin]);
        $member = User::factory()->create(['org_role' => OrgRole::Member]);

        $this->get(route('team.index'))->assertRedirect(route('login'));

        $this->actingAs($member)
            ->get(route('team.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('team.index'))
            ->assertOk();

        $this->actingAs($owner)
            ->get(route('team.index'))
            ->assertOk();
    }

    public function test_roles_can_be_changed_through_the_team_component_with_policy_enforcement(): void
    {
        $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
        $admin = User::factory()->create(['org_role' => OrgRole::Admin]);
        $member = User::factory()->create(['org_role' => OrgRole::Member]);

        $this->actingAs($owner);

        Livewire::test('pages::team')
            ->set("roleChanges.{$admin->id}", OrgRole::Member->value)
            ->call('changeRole', $admin->id)
            ->assertHasNoErrors();

        $this->assertSame(OrgRole::Member, $admin->refresh()->org_role);

        $admin->forceFill(['org_role' => OrgRole::Admin])->save();
        $this->actingAs($admin);

        Livewire::test('pages::team')
            ->set("roleChanges.{$member->id}", OrgRole::Admin->value)
            ->call('changeRole', $member->id)
            ->assertHasNoErrors();

        $this->assertSame(OrgRole::Admin, $member->refresh()->org_role);

        Livewire::test('pages::team')
            ->set("roleChanges.{$owner->id}", OrgRole::Member->value)
            ->call('changeRole', $owner->id)
            ->assertForbidden();

        $this->assertSame(OrgRole::Owner, $owner->refresh()->org_role);

        Livewire::test('pages::team')
            ->set("roleChanges.{$member->id}", OrgRole::Owner->value)
            ->call('changeRole', $member->id)
            ->assertForbidden();

        $this->assertSame(OrgRole::Admin, $member->refresh()->org_role);

        $this->actingAs($owner);

        Livewire::test('pages::team')
            ->set("roleChanges.{$member->id}", OrgRole::Owner->value)
            ->call('changeRole', $member->id)
            ->assertHasNoErrors();

        $this->assertSame(OrgRole::Owner, $member->refresh()->org_role);
    }

    public function test_crafted_invalid_role_values_are_rejected_without_changing_the_target(): void
    {
        $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
        $member = User::factory()->create(['org_role' => OrgRole::Member]);

        $this->actingAs($owner);

        Livewire::test('pages::team')
            ->set("roleChanges.{$member->id}", 'super-owner')
            ->call('changeRole', $member->id)
            ->assertHasErrors("roleChanges.{$member->id}");

        $this->assertSame(OrgRole::Member, $member->refresh()->org_role);
    }

    public function test_last_owner_cannot_be_demoted_through_component_or_direct_action(): void
    {
        $owner = User::factory()->create(['org_role' => OrgRole::Owner]);

        $this->actingAs($owner);

        Livewire::test('pages::team')
            ->set("roleChanges.{$owner->id}", OrgRole::Admin->value)
            ->call('changeRole', $owner->id)
            ->assertHasErrors("roleChanges.{$owner->id}");

        $this->assertSame(OrgRole::Owner, $owner->refresh()->org_role);

        try {
            app(ChangeOrgRole::class)->handle($owner, $owner, OrgRole::Admin);
            $this->fail('The sole owner demotion should fail.');
        } catch (ValidationException $exception) {
            $this->assertSame('The organization must always have at least one owner.', $exception->errors()['org_role'][0]);
            $this->assertSame(OrgRole::Owner, $owner->refresh()->org_role);
        }
    }

    public function test_one_of_two_owners_can_be_demoted(): void
    {
        $actor = User::factory()->create(['org_role' => OrgRole::Owner]);
        $target = User::factory()->create(['org_role' => OrgRole::Owner]);

        app(ChangeOrgRole::class)->handle($actor, $target, OrgRole::Admin);

        $this->assertSame(OrgRole::Admin, $target->refresh()->org_role);
        $this->assertSame(1, User::query()->where('org_role', OrgRole::Owner)->count());
    }

    public function test_last_owner_guard_runs_inside_the_change_role_transaction(): void
    {
        $actor = User::factory()->create(['org_role' => OrgRole::Owner]);
        $target = User::factory()->create(['org_role' => OrgRole::Owner]);

        $this->app->instance(EnsureAnotherOwnerRemains::class, new class($this) extends EnsureAnotherOwnerRemains
        {
            public function __construct(private readonly TestCase $test) {}

            public function handle(User $target): void
            {
                $this->test->assertGreaterThan(0, DB::transactionLevel());

                parent::handle($target);
            }
        });

        app(ChangeOrgRole::class)->handle($actor, $target, OrgRole::Admin);

        $this->assertSame(OrgRole::Admin, $target->refresh()->org_role);
    }

    public function test_change_role_rereads_a_stale_target_inside_the_transaction_before_checking_owner_loss(): void
    {
        $actor = User::factory()->create(['org_role' => OrgRole::Owner]);
        $target = User::factory()->create(['org_role' => OrgRole::Member]);

        $target->newQuery()->whereKey($target->id)->update(['org_role' => OrgRole::Owner]);

        $this->app->instance(EnsureAnotherOwnerRemains::class, new class($this) extends EnsureAnotherOwnerRemains
        {
            public function __construct(private readonly TestCase $test) {}

            public function handle(User $target): void
            {
                $this->test->assertSame(OrgRole::Owner, $target->org_role);

                parent::handle($target);
            }
        });

        app(ChangeOrgRole::class)->handle($actor, $target, OrgRole::Admin);

        $this->assertSame(OrgRole::Admin, $target->refresh()->org_role);
    }

    public function test_invitations_can_be_issued_through_the_team_component(): void
    {
        $admin = User::factory()->create(['org_role' => OrgRole::Admin]);

        $this->actingAs($admin);

        $component = Livewire::test('pages::team')
            ->set('invitationEmail', 'new-member@example.com')
            ->set('invitationRole', OrgRole::Member->value)
            ->call('issueInvitation')
            ->assertHasNoErrors();

        $invitation = Invitation::firstOrFail();

        $component->assertSee($invitation->code);
        $this->assertSame('new-member@example.com', $invitation->email);
        $this->assertSame(OrgRole::Member, $invitation->role);
        $this->assertSame($admin->id, $invitation->issued_by);
    }

    public function test_admin_cannot_issue_owner_invitation_through_a_crafted_component_request(): void
    {
        $admin = User::factory()->create(['org_role' => OrgRole::Admin]);

        $this->actingAs($admin);

        Livewire::test('pages::team')
            ->set('invitationRole', OrgRole::Owner->value)
            ->call('issueInvitation')
            ->assertForbidden();

        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_demoted_actor_cannot_issue_invitation_from_an_already_mounted_team_component(): void
    {
        $admin = User::factory()->create(['org_role' => OrgRole::Admin]);

        $this->actingAs($admin);

        $component = Livewire::test('pages::team');

        $admin->newQuery()->whereKey($admin->id)->update(['org_role' => OrgRole::Member]);

        $component
            ->set('invitationRole', OrgRole::Member->value)
            ->call('issueInvitation')
            ->assertForbidden();

        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_owner_can_issue_owner_invitation_through_the_team_component(): void
    {
        $owner = User::factory()->create(['org_role' => OrgRole::Owner]);

        $this->actingAs($owner);
        Livewire::test('pages::team')
            ->set('invitationRole', OrgRole::Owner->value)
            ->call('issueInvitation')
            ->assertHasNoErrors();

        $this->assertSame(OrgRole::Owner, Invitation::firstOrFail()->role);
    }

    public function test_claimable_invitations_can_be_revoked_and_used_invitations_are_not_listed(): void
    {
        $admin = User::factory()->create(['org_role' => OrgRole::Admin]);
        $usedBy = User::factory()->create(['org_role' => OrgRole::Member]);
        $claimable = Invitation::factory()->email('claimable@example.com')->create(['issued_by' => $admin->id]);
        $used = Invitation::factory()->email('used@example.com')->create([
            'issued_by' => $admin->id,
            'used_by' => $usedBy->id,
            'used_at' => now(),
        ]);

        $this->actingAs($admin);

        $this->get(route('team.index'))
            ->assertOk()
            ->assertSee('claimable@example.com')
            ->assertDontSee('used@example.com');

        Livewire::test('pages::team')
            ->call('revokeInvitation', $claimable->id)
            ->assertHasNoErrors();

        $this->assertNull($claimable->fresh());
        $this->assertNotNull($used->fresh());
    }

    public function test_demoted_actor_cannot_revoke_invitation_from_an_already_mounted_team_component(): void
    {
        $admin = User::factory()->create(['org_role' => OrgRole::Admin]);
        $claimable = Invitation::factory()->email('claimable@example.com')->create(['issued_by' => $admin->id]);

        $this->actingAs($admin);

        $component = Livewire::test('pages::team');

        $admin->newQuery()->whereKey($admin->id)->update(['org_role' => OrgRole::Member]);

        $component
            ->call('revokeInvitation', $claimable->id)
            ->assertForbidden();

        $this->assertNotNull($claimable->fresh());
    }

    public function test_owner_removes_member_and_database_relations_follow_cascade_rules(): void
    {
        $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
        $member = User::factory()->create(['org_role' => OrgRole::Member]);
        $team = Team::query()->create(['name' => 'Project', 'slug' => 'project', 'is_default' => false]);
        $team->users()->attach($member);
        $issuedInvitation = Invitation::factory()->create(['issued_by' => $member->id]);
        $usedInvitation = Invitation::factory()->create(['issued_by' => $owner->id, 'used_by' => $member->id, 'used_at' => now()]);
        $artifact = Artifact::factory()->create(['author_id' => $member->id]);

        $this->actingAs($owner);

        Livewire::test('pages::team')
            ->call('removeMember', $member->id)
            ->assertHasNoErrors();

        $this->assertNull($member->fresh());
        $this->assertDatabaseMissing('team_user', ['user_id' => $member->id]);
        $this->assertNull($issuedInvitation->fresh());
        $this->assertNull($usedInvitation->refresh()->used_by);
        $this->assertNull($artifact->refresh()->author_id);
    }

    public function test_admin_can_remove_member_but_cannot_remove_owner_through_crafted_component_call(): void
    {
        $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
        $admin = User::factory()->create(['org_role' => OrgRole::Admin]);
        $member = User::factory()->create(['org_role' => OrgRole::Member]);

        $this->actingAs($admin);

        Livewire::test('pages::team')
            ->call('removeMember', $member->id)
            ->assertHasNoErrors();

        $this->assertNull($member->fresh());

        Livewire::test('pages::team')
            ->call('removeMember', $owner->id)
            ->assertForbidden();

        $this->assertNotNull($owner->fresh());
    }

    public function test_self_removal_is_denied_for_owners_and_admins(): void
    {
        $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
        $admin = User::factory()->create(['org_role' => OrgRole::Admin]);

        $this->actingAs($owner);
        Livewire::test('pages::team')
            ->call('removeMember', $owner->id)
            ->assertForbidden();

        $this->assertNotNull($owner->fresh());

        $this->actingAs($admin);
        Livewire::test('pages::team')
            ->call('removeMember', $admin->id)
            ->assertForbidden();

        $this->assertNotNull($admin->fresh());
    }

    public function test_owner_can_remove_another_owner_and_remove_member_invokes_owner_guard_inside_transaction(): void
    {
        $actor = User::factory()->create(['org_role' => OrgRole::Owner]);
        $target = User::factory()->create(['org_role' => OrgRole::Owner]);

        $guard = new class($this) extends EnsureAnotherOwnerRemains
        {
            public bool $called = false;

            public function __construct(private readonly TestCase $test) {}

            public function handle(User $target): void
            {
                $this->called = true;
                $this->test->assertSame(OrgRole::Owner, $target->org_role);
                $this->test->assertGreaterThan(0, DB::transactionLevel());

                parent::handle($target);
            }
        };

        $this->app->instance(EnsureAnotherOwnerRemains::class, $guard);

        app(RemoveMember::class)->handle($actor, $target);

        $this->assertNull($target->fresh());
        $this->assertTrue($guard->called);
        $this->assertSame(1, User::query()->where('org_role', OrgRole::Owner)->count());
    }

    public function test_remaining_owner_cannot_remove_themself_after_another_owner_was_removed(): void
    {
        $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
        $removedOwner = User::factory()->create(['org_role' => OrgRole::Owner]);

        app(RemoveMember::class)->handle($owner, $removedOwner);

        try {
            app(RemoveMember::class)->handle($owner, $owner);
            $this->fail('Owners should not be able to remove themselves from team management.');
        } catch (AuthorizationException) {
            $this->assertNotNull($owner->fresh());
            $this->assertSame(1, User::query()->where('org_role', OrgRole::Owner)->count());
        }
    }

    public function test_demoted_actor_cannot_remove_member_from_an_already_mounted_team_component(): void
    {
        $admin = User::factory()->create(['org_role' => OrgRole::Admin]);
        $target = User::factory()->create(['org_role' => OrgRole::Member]);

        $this->actingAs($admin);

        $component = Livewire::test('pages::team');

        $admin->newQuery()->whereKey($admin->id)->update(['org_role' => OrgRole::Member]);

        $component
            ->call('removeMember', $target->id)
            ->assertForbidden();

        $this->assertNotNull($target->fresh());
    }
}
