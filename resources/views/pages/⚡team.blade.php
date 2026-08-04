<?php

use App\Actions\ChangeOrgRole;
use App\Actions\IssueInvitation;
use App\Actions\RevokeInvitation;
use App\Enums\OrgRole;
use App\Models\Invitation;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Team')] class extends Component {
    public array $roleChanges = [];

    public ?string $invitationEmail = null;

    public string $invitationRole = 'member';

    public ?string $issuedInvitationLink = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->canIssueInvitations(), 403);

        $this->roleChanges = User::query()
            ->pluck('org_role', 'id')
            ->map(fn (mixed $role): string => $role instanceof OrgRole ? $role->value : (string) $role)
            ->all();
    }

    public function changeRole(int $userId, ChangeOrgRole $changeOrgRole): void
    {
        $target = User::query()->findOrFail($userId);
        $role = OrgRole::from($this->roleChanges[$userId] ?? $target->org_role->value);

        try {
            $changeOrgRole->handle(Auth::user(), $target, $role);
        } catch (ValidationException $exception) {
            $this->roleChanges[$userId] = $target->refresh()->org_role->value;
            $this->addError("roleChanges.{$userId}", $exception->errors()['org_role'][0] ?? $exception->getMessage());

            return;
        }

        $this->roleChanges[$userId] = $target->refresh()->org_role->value;
        Flux::toast(variant: 'success', text: __('Role updated.'));
    }

    public function issueInvitation(IssueInvitation $issueInvitation): void
    {
        $validated = $this->validate([
            'invitationEmail' => ['nullable', 'email'],
            'invitationRole' => ['required', Rule::in(array_column(OrgRole::cases(), 'value'))],
        ]);

        $invitation = $issueInvitation->handle(
            issuer: Auth::user(),
            email: $validated['invitationEmail'] ?: null,
            role: OrgRole::from($validated['invitationRole']),
        );

        $this->issuedInvitationLink = url(route('register', ['code' => $invitation->code], false));
        $this->invitationEmail = null;
        $this->invitationRole = OrgRole::Member->value;
    }

    public function revokeInvitation(int $invitationId, RevokeInvitation $revokeInvitation): void
    {
        $invitation = Invitation::query()->stillClaimable()->findOrFail($invitationId);

        $revokeInvitation->handle(Auth::user(), $invitation);
        Flux::toast(variant: 'success', text: __('Invitation revoked.'));
    }

    public function roleOptionsFor(User $target): array
    {
        return collect(OrgRole::cases())
            ->filter(fn (OrgRole $role): bool => Auth::user()->canChangeOrgRoleTo($target, $role))
            ->mapWithKeys(fn (OrgRole $role): array => [$role->value => str($role->value)->headline()->toString()])
            ->all();
    }

    #[Computed]
    public function users()
    {
        return User::query()->orderBy('name')->orderBy('email')->get();
    }

    #[Computed]
    public function invitations()
    {
        return Invitation::query()
            ->with('issuer')
            ->stillClaimable()
            ->latest()
            ->get();
    }
}; ?>

<section class="w-full space-y-8">
        <div class="space-y-2">
            <flux:heading size="xl" level="1">{{ __('Team') }}</flux:heading>
            <flux:subheading>{{ __('Manage members, roles, and outstanding invitations.') }}</flux:subheading>
        </div>

        <section class="space-y-4 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <div>
                <flux:heading>{{ __('Members') }}</flux:heading>
                <flux:subheading>{{ __('Every registered user in this Capstan organization.') }}</flux:subheading>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        <tr>
                            <th class="py-3 pe-4 font-medium">{{ __('Name') }}</th>
                            <th class="py-3 pe-4 font-medium">{{ __('Email') }}</th>
                            <th class="py-3 pe-4 font-medium">{{ __('Joined') }}</th>
                            <th class="py-3 font-medium">{{ __('Role') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->users as $user)
                            <tr wire:key="member-{{ $user->id }}">
                                <td class="py-4 pe-4 font-medium text-zinc-900 dark:text-zinc-100">{{ $user->name }}</td>
                                <td class="py-4 pe-4 text-zinc-600 dark:text-zinc-300">{{ $user->email }}</td>
                                <td class="py-4 pe-4 text-zinc-600 dark:text-zinc-300">{{ $user->created_at?->toFormattedDateString() }}</td>
                                <td class="min-w-44 py-4">
                                    <flux:select
                                        wire:model="roleChanges.{{ $user->id }}"
                                        wire:change="changeRole({{ $user->id }})"
                                        size="sm"
                                        aria-label="{{ __('Role for :name', ['name' => $user->name]) }}"
                                    >
                                        @foreach ($this->roleOptionsFor($user) as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </flux:select>
                                    @error("roleChanges.{$user->id}")
                                        <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
            <div class="space-y-4 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <div>
                    <flux:heading>{{ __('Invite someone') }}</flux:heading>
                    <flux:subheading>{{ __('Create a registration link for a new teammate.') }}</flux:subheading>
                </div>

                <form wire:submit="issueInvitation" class="space-y-4">
                    <flux:input wire:model="invitationEmail" :label="__('Email optional')" type="email" autocomplete="email" />

                    <div class="space-y-2">
                        <flux:label>{{ __('Role') }}</flux:label>
                        <flux:select wire:model="invitationRole">
                            <option value="{{ OrgRole::Member->value }}">{{ __('Member') }}</option>
                            <option value="{{ OrgRole::Admin->value }}">{{ __('Admin') }}</option>
                            @if (auth()->user()->org_role === OrgRole::Owner)
                                <option value="{{ OrgRole::Owner->value }}">{{ __('Owner') }}</option>
                            @endif
                        </flux:select>
                    </div>

                    <flux:button type="submit" variant="primary">{{ __('Create invite') }}</flux:button>
                </form>

                @if ($issuedInvitationLink)
                    <div class="space-y-2 rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800">
                        <flux:label>{{ __('Invite link') }}</flux:label>
                        <div class="flex gap-2" x-data="{ link: @js($issuedInvitationLink) }">
                            <flux:input readonly x-bind:value="link" aria-label="{{ __('Invite link') }}" />
                            <flux:button type="button" x-on:click="navigator.clipboard?.writeText(link)">{{ __('Copy') }}</flux:button>
                        </div>
                    </div>
                @endif
            </div>

            <div class="space-y-4 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <div>
                    <flux:heading>{{ __('Outstanding invitations') }}</flux:heading>
                    <flux:subheading>{{ __('Claimable links that have not expired or been used.') }}</flux:subheading>
                </div>

                @if ($this->invitations->isEmpty())
                    <flux:text>{{ __('No outstanding invitations.') }}</flux:text>
                @else
                    <div class="space-y-3">
                        @foreach ($this->invitations as $invitation)
                            <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700 sm:flex-row sm:items-center sm:justify-between" wire:key="invitation-{{ $invitation->id }}">
                                <div class="space-y-1">
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $invitation->email ?: __('link only') }}</div>
                                    <div class="text-sm text-zinc-600 dark:text-zinc-300">
                                        {{ str($invitation->role->value)->headline() }} · {{ __('expires :date', ['date' => $invitation->expires_at?->toFormattedDateString() ?? __('never')]) }} · {{ __('issued by :name', ['name' => $invitation->issuer?->name ?? __('Unknown')]) }}
                                    </div>
                                </div>

                                <flux:button type="button" variant="danger" size="sm" wire:click="revokeInvitation({{ $invitation->id }})">
                                    {{ __('Revoke') }}
                                </flux:button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
</section>
