<?php

namespace Tests\Feature;

use App\Actions\ChangeOrgRole;
use App\Actions\IssueInvitation;
use App\Enums\OrgRole;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_issuing_invitation_with_defaults_persists_member_role_no_email_and_default_expiry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00'));

        try {
            $owner = User::factory()->create(['org_role' => OrgRole::Owner]);

            $invitation = app(IssueInvitation::class)->handle($owner);

            $this->assertSame(OrgRole::Member, $invitation->role);
            $this->assertNull($invitation->email);
            $this->assertTrue($invitation->expires_at->equalTo(now()->addDays(14)));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_issuing_invitation_with_explicit_email_role_and_expiry_persists_all_three(): void
    {
        $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
        $expiresAt = now()->addDays(3);

        $invitation = app(IssueInvitation::class)->handle(
            issuer: $owner,
            email: 'invited@example.com',
            role: OrgRole::Admin,
            expiresAt: $expiresAt,
        );

        $this->assertSame('invited@example.com', $invitation->email);
        $this->assertSame(OrgRole::Admin, $invitation->role);
        $this->assertSame($expiresAt->getTimestamp(), $invitation->expires_at->getTimestamp());
    }

    public function test_only_owners_can_issue_owner_invitations(): void
    {
        $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
        $admin = User::factory()->create(['org_role' => OrgRole::Admin]);
        $member = User::factory()->create(['org_role' => OrgRole::Member]);

        try {
            app(IssueInvitation::class)->handle($admin, role: OrgRole::Owner);
            $this->fail('Admins should not be allowed to issue owner invitations.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('invitations', 0);
        }

        $invitation = app(IssueInvitation::class)->handle($owner, role: OrgRole::Owner);

        $this->assertSame(OrgRole::Owner, $invitation->role);
        $this->assertDatabaseCount('invitations', 1);

        try {
            app(IssueInvitation::class)->handle($member);
            $this->fail('Members should not be allowed to issue invitations.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('invitations', 1);
        }
    }
}
