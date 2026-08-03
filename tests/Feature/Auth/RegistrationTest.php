<?php

namespace Tests\Feature\Auth;

use App\Actions\RegisterFirstOwner;
use App\Enums\OrgRole;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_first_user_can_register_as_owner(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'org_role' => OrgRole::Owner->value,
        ]);

        $this->assertAuthenticated();
    }

    public function test_registration_attempts_are_rate_limited(): void
    {
        $route = Route::getRoutes()->getByName('register.store');

        $this->assertNotNull($route);
        $this->assertContains('throttle:register', $route->middleware());
    }

    public function test_registration_screen_requires_valid_invitation_after_first_user(): void
    {
        User::factory()->create(['org_role' => OrgRole::Owner]);

        $this->get(route('register'))->assertForbidden();
        $this->get(route('register', ['code' => 'invalid']))->assertForbidden();
    }

    public function test_invited_user_can_register_as_member(): void
    {
        $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
        $invitation = Invitation::factory()->create(['issued_by' => $owner->id]);

        $this->get(route('register', ['code' => $invitation->code]))->assertOk();

        $response = $this->post(route('register.store'), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invitation_code' => $invitation->code,
        ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', 'jane@example.com')->firstOrFail();

        $this->assertSame(OrgRole::Member, $user->org_role);
        $this->assertSame($user->id, $invitation->refresh()->used_by);
        $this->assertNotNull($invitation->used_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_invitation_code_cannot_be_used_twice(): void
    {
        $owner = User::factory()->create(['org_role' => OrgRole::Owner]);
        $invitation = Invitation::factory()->create(['issued_by' => $owner->id]);

        $this->post(route('register.store'), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invitation_code' => $invitation->code,
        ])->assertSessionHasNoErrors();

        auth()->logout();

        $this->post(route('register.store'), [
            'name' => 'Second User',
            'email' => 'second@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invitation_code' => $invitation->code,
        ])->assertSessionHasErrors('invitation_code');

        $this->assertDatabaseMissing('users', [
            'email' => 'second@example.com',
        ]);
    }

    public function test_invalid_invitation_code_is_rejected(): void
    {
        User::factory()->create(['org_role' => OrgRole::Owner]);

        $this->post(route('register.store'), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invitation_code' => 'invalid',
        ])->assertSessionHasErrors('invitation_code');

        $this->assertDatabaseMissing('users', [
            'email' => 'jane@example.com',
        ]);
    }

    public function test_invalid_invitation_code_is_rejected_before_duplicate_email_validation(): void
    {
        $existing = User::factory()->create(['org_role' => OrgRole::Owner]);

        $response = $this->post(route('register.store'), [
            'name' => 'Jane Doe',
            'email' => $existing->email,
            'password' => 'password',
            'password_confirmation' => 'password',
            'invitation_code' => 'invalid',
        ]);

        $response->assertSessionHasErrors('invitation_code');
        $response->assertSessionDoesntHaveErrors('email');
    }

    public function test_first_owner_claim_allows_only_one_owner_when_claim_path_competes(): void
    {
        app(RegisterFirstOwner::class)->handle([
            'name' => 'First Owner',
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);

        try {
            app(RegisterFirstOwner::class)->handle([
                'name' => 'Second Owner',
                'email' => 'second-owner@example.com',
                'password' => 'password',
            ]);
            $this->fail('The second first-owner claim should fail.');
        } catch (ValidationException) {
            $this->assertSame(1, User::query()->where('org_role', OrgRole::Owner)->count());
        }
    }

    public function test_sole_user_can_delete_account_and_registration_reopens_for_new_owner(): void
    {
        $this->post(route('register.store'), [
            'name' => 'First Owner',
            'email' => 'owner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();

        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertSame(0, User::query()->count());
        $this->get(route('register'))->assertOk();

        $this->post(route('register.store'), [
            'name' => 'Second Owner',
            'email' => 'second-owner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'second-owner@example.com',
            'org_role' => OrgRole::Owner->value,
        ]);
    }

    public function test_stale_first_owner_claim_reclaim_path_still_allows_only_one_owner(): void
    {
        DB::table('first_owner_claims')->insert([
            'name' => 'first_owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(RegisterFirstOwner::class)->handle([
            'name' => 'Reclaimed Owner',
            'email' => 'reclaimed-owner@example.com',
            'password' => 'password',
        ]);

        try {
            app(RegisterFirstOwner::class)->handle([
                'name' => 'Competing Owner',
                'email' => 'competing-owner@example.com',
                'password' => 'password',
            ]);
            $this->fail('The competing first-owner reclaim should fail.');
        } catch (ValidationException) {
            $this->assertSame(1, User::query()->where('org_role', OrgRole::Owner)->count());
        }
    }
}
