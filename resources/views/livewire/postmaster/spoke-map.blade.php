<section class="w-full space-y-6" wire:poll.30s>
    <style>
        .postmaster-onboarding-row { align-items: start; display: grid; gap: .75rem; }
        .postmaster-onboarding-action { width: 100%; }
        @media (min-width: 640px) {
            .postmaster-onboarding-row { grid-template-columns: minmax(0, 1fr) auto; }
            .postmaster-onboarding-action { width: auto; }
        }
    </style>

    <div data-testid="machine-map-heading">
        <flux:heading size="xl">{{ __('Machine map') }}</flux:heading>
        <flux:subheading>{{ __('Registered machines, routing health, and inference headroom for the selected team.') }}</flux:subheading>
    </div>

    <flux:field data-testid="machine-team-selector">
        <flux:label>{{ __('Work team') }}</flux:label>
        <flux:select wire:model.live="selectedTeamId">
            @foreach ($teams as $team)
                <option value="{{ $team['id'] }}">{{ $team['name'] }}</option>
            @endforeach
        </flux:select>
        <flux:description>{{ __('Work profiles shown below apply to :team.', ['team' => $selectedTeam['name']]) }}</flux:description>
    </flux:field>

    <section class="space-y-3 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-testid="machine-budget-summary">
        <div>
            <flux:heading size="lg">{{ __('Per-person inference headroom') }}</flux:heading>
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $machineBudgets['routing_aid_notice'] }}</flux:text>
        </div>
        @forelse ($machineBudgets['people'] as $person)
            <div class="space-y-2" data-testid="machine-budget-person">
                <flux:heading size="sm">{{ $person['owner_name'] }}</flux:heading>
                @forelse ($person['providers'] as $provider)
                    <div class="rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800" data-testid="machine-budget-provider">
                        <span class="font-medium">{{ $provider['provider'] }}</span>
                        <span aria-hidden="true">&middot;</span>
                        {{ __('Best fresh: :value', ['value' => $provider['best_fresh_remaining_percent'] === null ? __('Unknown') : $provider['best_fresh_remaining_percent'].'%']) }}
                        <span aria-hidden="true">&middot;</span>
                        {{ __('Stale: :stale, unknown: :unknown', ['stale' => $provider['stale_machines_count'], 'unknown' => $provider['unknown_machines_count']]) }}
                        @if ($provider['earliest_corresponding_reset_at'] !== null)
                            <span aria-hidden="true">&middot;</span>
                            {{ __('Reset :time', ['time' => \Carbon\CarbonImmutable::parse($provider['earliest_corresponding_reset_at'])->diffForHumans()]) }}
                        @endif
                    </div>
                @empty
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No provider budget has been reported.') }}</flux:text>
                @endforelse
            </div>
        @empty
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No machines serve this team yet.') }}</flux:text>
        @endforelse
    </section>

    @if ($showUsageToolGuidance)
        <section class="space-y-4 rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-800 dark:bg-amber-950/30" data-testid="machine-usage-tool-guidance">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Add usage reporting') }}</flux:heading>
                <flux:text class="text-sm text-zinc-700 dark:text-zinc-300">
                    {{ __('One or more machines cannot report provider usage yet. This build detects tools but never installs them.') }}
                </flux:text>
            </div>

            <details class="rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-950" data-testid="machine-usage-linux-guidance">
                <summary class="cursor-pointer font-medium text-zinc-700 dark:text-zinc-300">{{ __('Linux: install the pinned OpenUsage.sh release') }}</summary>
                <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('This verifies the separately downloaded checksums file before installing into ~/.local/bin, one of the paths checked on every collection. The other lookup paths are ~/bin, /opt/homebrew/bin, /usr/local/bin, and /usr/bin.') }}
                </flux:text>
                <pre class="mt-3 overflow-auto rounded-lg bg-zinc-950 p-3 text-xs leading-5 text-zinc-100"><code>version=v0.26.1
case "$(uname -m)" in
    x86_64) archive=OpenUsage_Linux_x86_64.tar.gz ;;
    aarch64) archive=OpenUsage_Linux_aarch64.tar.gz ;;
    *) printf '%s\n' 'Unsupported Linux architecture.' >&amp;2; exit 1 ;;
esac
base_url="https://github.com/janekbaraniewski/openusage/releases/download/$version"
work_dir="$(mktemp -d)" || exit 1
trap 'rm -rf "$work_dir"' EXIT HUP INT TERM
cd "$work_dir" || exit 1
curl --fail --location --proto '=https' --tlsv1.2 --output "$archive" "$base_url/$archive"
curl --fail --location --proto '=https' --tlsv1.2 --output checksums.txt "$base_url/checksums.txt"
expected="$(awk -v name="$archive" '$2 == name || $2 == "*" name { print $1 }' checksums.txt)"
[ -n "$expected" ] &amp;&amp; [ "$(printf '%s\n' "$expected" | wc -l)" -eq 1 ] || { printf '%s\n' 'Published digest missing or ambiguous.' >&amp;2; exit 1; }
printf '%s\n' "$expected" | grep -Eq '^[[:xdigit:]]{64}$' || { printf '%s\n' 'Published digest is invalid.' >&amp;2; exit 1; }
if command -v sha256sum >/dev/null 2>&amp;1; then
    actual="$(sha256sum "$archive" | awk '{ print $1 }')"
elif command -v shasum >/dev/null 2>&amp;1; then
    actual="$(shasum -a 256 "$archive" | awk '{ print $1 }')"
else
    printf '%s\n' 'A SHA-256 checker is required.' >&amp;2
    exit 1
fi
[ "$actual" = "$expected" ] || { printf '%s\n' 'Checksum mismatch.' >&amp;2; exit 1; }
tar -xzf "$archive"
candidate="$(find "$work_dir" -type f -name openusage -perm -u+x -print)"
[ -n "$candidate" ] &amp;&amp; [ "$(printf '%s\n' "$candidate" | wc -l)" -eq 1 ] || { printf '%s\n' 'Expected one OpenUsage executable.' >&amp;2; exit 1; }
mkdir -p "$HOME/.local/bin"
install -m 0755 "$candidate" "$HOME/.local/bin/openusage"</code></pre>
            </details>

            <details class="rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-950" data-testid="machine-usage-macos-guidance">
                <summary class="cursor-pointer font-medium text-zinc-700 dark:text-zinc-300">{{ __('macOS: automatic app probe') }}</summary>
                <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('Capstan probes the OpenUsage app automatically on every collection. OpenUsage app v0.7.15 bundles PostHog, and this Capstan build cannot prove that its telemetry can be disabled, so the app is not the default recommendation. Privacy-sensitive or advanced users can install the verified OpenUsage.sh CLI instead.') }}
                </flux:text>
            </details>
        </section>
    @endif

    <section
        class="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-900"
        data-testid="postmaster-onboarding"
        x-data="{ command: '', script: '', expiresAt: null }"
        x-on:postmaster-installer-generated.window="command = $event.detail.command; script = $event.detail.script; expiresAt = $event.detail.expiresAt"
    >
        <div class="postmaster-onboarding-row" data-testid="postmaster-onboarding-row">
            <div class="min-w-0">
                <flux:heading size="lg">{{ __('Connect a local agent') }}</flux:heading>
                <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Generate a short-lived package device authorization installer.') }}
                </flux:text>
            </div>

            <flux:button class="postmaster-onboarding-action" type="button" size="sm" wire:click="generateOnboardingSnippet" wire:loading.attr="disabled">
                <span x-text="command === '' ? @js(__('Generate installer')) : @js(__('Generate another'))"></span>
            </flux:button>
        </div>

        <div class="space-y-3" x-cloak x-show="command !== ''" data-testid="postmaster-installer">
            <flux:input
                x-model="command"
                readonly
                copyable
                aria-label="{{ __('Install command') }}"
                class:input="font-mono text-xs"
                data-testid="postmaster-install-command"
            />
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                <span x-text="expiresAt === null ? '' : @js(__('Expires at')).concat(' ', new Date(expiresAt * 1000).toLocaleTimeString(), '.')"></span>
            </flux:text>

            <details class="rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-950">
                <summary class="cursor-pointer text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Show full script') }}</summary>
                <pre class="mt-3 max-h-80 overflow-auto text-xs leading-5 text-zinc-700 dark:text-zinc-200"><code x-text="script"></code></pre>
            </details>
        </div>
    </section>

    @if ($spokes->isEmpty())
        <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-5 py-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No spokes have registered yet.') }}</flux:text>
        </div>
    @else
        @error('retireSpoke')
            <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
        @enderror
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($spokes as $spoke)
                    @php($machineBudget = collect($machineBudgets['machines'])->firstWhere('machine_id', $spoke['id']))
                    <div
                        class="grid gap-3 px-4 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-5"
                        data-spoke-id="{{ $spoke['id'] }}"
                        data-status="{{ $spoke['status'] }}"
                        data-inbox-count="{{ $spoke['inboxes_count'] }}"
                        wire:key="spoke-{{ $selectedTeam['id'] }}-{{ $spoke['id'] }}"
                        data-testid="machine-map-row"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <span
                                @class([
                                    'mt-1.5 size-2.5 shrink-0 rounded-full ring-4',
                                    'bg-emerald-500 ring-emerald-500/15' => $spoke['status'] === 'green',
                                    'bg-red-500 ring-red-500/15' => $spoke['status'] === 'red',
                                    'bg-amber-500 ring-amber-500/15' => $spoke['status'] === 'pending',
                                ])
                                role="img"
                                aria-label="{{ ucfirst($spoke['status']) }}"
                            ></span>

                            <div class="min-w-0 flex-1">
                                <flux:heading size="lg" class="truncate">{{ $spoke['name'] }}</flux:heading>
                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ __('Owned by :name', ['name' => $spoke['owner_name']]) }}
                                </flux:text>

                                @if (($machineBudget['reactivation_required'] ?? false) === true)
                                    <flux:text class="mt-2 text-sm text-amber-700 dark:text-amber-300" data-testid="machine-reactivation-guidance">
                                        {{ __('Reinstall on this machine and approve its new owner to reactivate it.') }}
                                    </flux:text>
                                @endif

                                @if ($spoke['can_rename'])
                                    @if ($renamingSpokeId === $spoke['id'])
                                        <form
                                            class="mt-3 grid gap-2 sm:grid-cols-[minmax(12rem,1fr)_auto_auto]"
                                            wire:submit="renameSpoke({{ $spoke['id'] }})"
                                            data-testid="postmaster-spoke-rename-form"
                                        >
                                            <flux:input
                                                wire:model="spokeName"
                                                maxlength="64"
                                                aria-label="{{ __('Spoke name') }}"
                                                placeholder="{{ $spoke['device_name'] ?? __('Spoke name') }}"
                                            />
                                            <flux:button type="submit" size="sm">{{ __('Save') }}</flux:button>
                                            <flux:button type="button" size="sm" variant="ghost" wire:click="cancelRenaming">
                                                {{ __('Cancel') }}
                                            </flux:button>
                                            @error('spokeName')
                                                <flux:text class="text-sm text-red-600 sm:col-span-3 dark:text-red-400">{{ $message }}</flux:text>
                                            @enderror
                                        </form>
                                    @else
                                        <flux:button
                                            class="mt-2"
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            wire:click="beginRenaming({{ $spoke['id'] }})"
                                        >
                                            {{ __('Rename') }}
                                        </flux:button>
                                        <flux:button
                                            class="mt-2"
                                            type="button"
                                            size="sm"
                                            variant="danger"
                                            wire:click="retireSpoke({{ $spoke['id'] }})"
                                            wire:confirm="{{ __('Retire this spoke? It will stop polling and disappear from the map. Its history will be kept.') }}"
                                        >
                                            {{ __('Retire') }}
                                        </flux:button>
                                    @endif
                                @endif

                                @if ($spoke['can_manage_work'])
                                    @if ($editingWorkSpokeId === $spoke['id'])
                                        <form class="mt-3 grid gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:submit="saveWorkProfile({{ $spoke['id'] }})" data-testid="machine-work-profile-form">
                                            <flux:checkbox wire:model="workEnabled" label="{{ __('Accept Work') }}" />
                                            <flux:input wire:model="workRoles" label="{{ __('Roles') }}" description="{{ __('Comma-separated') }}" />
                                            <flux:input wire:model="workCapabilities" label="{{ __('Capabilities') }}" description="{{ __('Comma-separated') }}" />
                                            <flux:input wire:model="workRoleProviders" label="{{ __('Role providers') }}" description="{{ __('JSON object mapping roles to provider families') }}" />
                                            <flux:input wire:model="workConcurrencyCeiling" type="number" min="1" max="25" label="{{ __('Concurrency') }}" />
                                            @error('workProfile')
                                                <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                                            @enderror
                                            <div class="flex gap-2">
                                                <flux:button type="submit" size="sm">{{ __('Save Work profile') }}</flux:button>
                                                <flux:button type="button" size="sm" variant="ghost" wire:click="cancelEditingWorkProfile">{{ __('Cancel') }}</flux:button>
                                            </div>
                                        </form>
                                    @else
                                        <div class="mt-2 flex items-center gap-2" data-testid="machine-work-profile-summary">
                                            <flux:badge color="{{ $spoke['work_enabled'] ? 'green' : 'zinc' }}">
                                                {{ $spoke['work_enabled'] ? __('Work on') : __('Work off') }}
                                            </flux:badge>
                                            <flux:button type="button" size="sm" variant="ghost" wire:click="beginEditingWorkProfile({{ $spoke['id'] }})">
                                                {{ __('Edit Work profile') }}
                                            </flux:button>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </div>

                        <div class="grid gap-1 text-left sm:text-right">
                            <flux:text class="text-sm text-zinc-700 dark:text-zinc-300">
                                {{ __('Last polled :time', [
                                    'time' => $spoke['last_polled_at'] === null ? __('Never') : \Carbon\CarbonImmutable::parse($spoke['last_polled_at'])->diffForHumans(),
                                ]) }}
                            </flux:text>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ __('Inboxes: :count', ['count' => $spoke['inboxes_count']]) }}
                                <span aria-hidden="true">&middot;</span>
                                {{ __('Probe: :state', [
                                    'state' => ucfirst($spoke['probe_status']),
                                ]) }}
                            </flux:text>
                            @if (is_array($machineBudget))
                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ __('Lifecycle: :state', ['state' => $machineBudget['lifecycle_state']]) }}
                                    <span aria-hidden="true">&middot;</span>
                                    {{ __('Usage: :freshness', ['freshness' => $machineBudget['usage']['freshness']]) }}
                                </flux:text>
                                @foreach ($machineBudget['usage']['providers'] as $provider)
                                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $provider['provider'] }}:
                                        {{ $provider['remaining_percent'] === null ? __('unknown') : $provider['remaining_percent'].'%' }}
                                        ({{ $provider['freshness'] }})
                                    </flux:text>
                                @endforeach
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</section>
