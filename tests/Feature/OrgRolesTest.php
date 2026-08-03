<?php

namespace Tests\Feature;

use App\Actions\ChangeOrgRole;
use App\Actions\IssueInvitation;
use App\Enums\OrgRole;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class OrgRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_promote_demote_and_elevate_roles(): void
    {
        $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
        $member = User::factory()->create(['org_role' => OrgRole::Member]);
        $admin = User::factory()->create(['org_role' => OrgRole::Admin]);

        $this->assertTrue(Gate::forUser($owner)->allows('updateOrgRole', [$member, OrgRole::Admin]));

        app(ChangeOrgRole::class)->handle($owner, $member, OrgRole::Admin);
        $this->assertSame(OrgRole::Admin, $member->refresh()->org_role);

        app(ChangeOrgRole::class)->handle($owner, $member, OrgRole::Member);
        $this->assertSame(OrgRole::Member, $member->refresh()->org_role);

        app(ChangeOrgRole::class)->handle($owner, $admin, OrgRole::Owner);
        $this->assertSame(OrgRole::Owner, $admin->refresh()->org_role);
    }

    public function test_admin_cannot_elevate_anyone_to_owner(): void
    {
        $admin = User::factory()->create(['org_role' => OrgRole::Admin]);
        $member = User::factory()->create(['org_role' => OrgRole::Member]);

        $this->assertFalse(Gate::forUser($admin)->allows('updateOrgRole', [$member, OrgRole::Owner]));
        $this->expectException(AuthorizationException::class);

        app(ChangeOrgRole::class)->handle($admin, $member, OrgRole::Owner);
    }

    public function test_member_cannot_change_any_role(): void
    {
        $member = User::factory()->create(['org_role' => OrgRole::Member]);
        $target = User::factory()->create(['org_role' => OrgRole::Member]);

        $this->assertFalse(Gate::forUser($member)->allows('updateOrgRole', [$target, OrgRole::Admin]));
        $this->expectException(AuthorizationException::class);

        app(ChangeOrgRole::class)->handle($member, $target, OrgRole::Admin);
    }

    public function test_owner_and_admin_can_issue_invitations_but_member_cannot(): void
    {
        $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
        $admin = User::factory()->create(['org_role' => OrgRole::Admin]);
        $member = User::factory()->create(['org_role' => OrgRole::Member]);

        $this->assertTrue(Gate::forUser($owner)->allows('create', Invitation::class));
        $this->assertTrue(Gate::forUser($admin)->allows('create', Invitation::class));
        $this->assertFalse(Gate::forUser($member)->allows('create', Invitation::class));

        $this->assertInstanceOf(Invitation::class, app(IssueInvitation::class)->handle($owner));
        $this->assertInstanceOf(Invitation::class, app(IssueInvitation::class)->handle($admin));

        $this->expectException(AuthorizationException::class);
        app(IssueInvitation::class)->handle($member);
    }
}
