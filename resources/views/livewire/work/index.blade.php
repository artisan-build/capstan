<div class="space-y-6">
    <flux:heading size="xl">{{ __('Work') }}</flux:heading>
    <section data-testid="work-pool-setting-{{ $team->pool_work ? 'on' : 'off' }}" class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="lg">{{ __('Pooled work') }}</flux:heading>
        <flux:text>{{ __('Changes apply to the :team team.', ['team' => $team->name]) }}</flux:text>
        <flux:text>{{ __('When pooled work is on, new tasks can be claimed by any of this team\'s machines. When it is off, a new task stays with the person who created it, unless they address it to someone else.') }}</flux:text>
        <flux:text>{{ __('Existing tasks are not affected. Only tasks created after a change follow the new setting.') }}</flux:text>
        <flux:badge color="{{ $team->pool_work ? 'green' : 'zinc' }}">{{ $team->pool_work ? __('On') : __('Off') }}</flux:badge>
        @if ($canManagePoolWork)
            <flux:button data-testid="work-pool-setting-toggle" wire:click="setPoolWork({{ $team->pool_work ? 'false' : 'true' }})">
                {{ $team->pool_work ? __('Turn pooled work off') : __('Turn pooled work on') }}
            </flux:button>
        @else
            <flux:button data-testid="work-pool-setting-toggle" disabled>
                {{ $team->pool_work ? __('Turn pooled work off') : __('Turn pooled work on') }}
            </flux:button>
            <flux:text>{{ __('Only an Owner or Admin can change this.') }}</flux:text>
        @endif
        <flux:error name="poolWork" />
    </section>
    <section data-testid="work-escalations" class="space-y-4">
        <flux:heading size="lg">{{ __('Escalations') }}</flux:heading>
        <flux:text>{{ __('Pending questions addressed to you, up to 25.') }}</flux:text>
        @forelse ($escalations as $escalation)
            <article data-testid="work-escalation" class="space-y-3 rounded-lg border border-amber-300 p-4 dark:border-amber-700">
                <flux:badge color="amber">{{ __('Pending') }}</flux:badge>
                <pre class="whitespace-pre-wrap break-all">{{ $escalation->question }}</pre>
                @if (is_array($escalation->metadata['options'] ?? null))
                    <ul class="list-disc space-y-1 ps-5">
                        @foreach ($escalation->metadata['options'] as $option)
                            @if (is_string($option))
                                <li>{{ $option }}</li>
                            @endif
                        @endforeach
                    </ul>
                @endif
                @if (is_string($escalation->metadata['recommendation'] ?? null))
                    <flux:text>{{ __('Recommendation') }}: {{ $escalation->metadata['recommendation'] }}</flux:text>
                @endif
                <form wire:submit="answerEscalation('{{ $escalation->id }}')" class="space-y-2">
                    <flux:textarea wire:model="answers.{{ $escalation->id }}" label="{{ __('Answer') }}" />
                    <flux:error name="answers.{{ $escalation->id }}" />
                    <flux:button type="submit" variant="primary">{{ __('Answer escalation') }}</flux:button>
                </form>
            </article>
        @empty
            <flux:text>{{ __('No pending escalations.') }}</flux:text>
        @endforelse
    </section>
    <section data-testid="work-board" class="space-y-3">
        <flux:text>{{ __('Latest 25 root tasks.') }}</flux:text>
        <div class="grid gap-4 xl:grid-cols-3">
            @foreach (['queued' => __('Queued'), 'running' => __('Running'), 'finished' => __('Finished')] as $column => $label)
                <section data-testid="work-column-{{ $column }}" class="space-y-3 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-900">
                    <flux:heading size="lg">{{ $label }}</flux:heading>
                    @forelse ($boardColumns[$column] as $card)
                        <article data-testid="work-card" data-task-id="{{ $card['task']->id }}" class="space-y-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                            <flux:heading>{{ $card['task']->title }}</flux:heading>
                            <flux:text>{{ $card['task']->owner_actor }} · {{ $card['task']->priority->value }}</flux:text>

                            @if ($card['children']->isNotEmpty())
                                <ul class="space-y-2">
                                    @foreach ($card['children'] as $child)
                                        <li data-testid="work-child">
                                            @if ($child['prUrl'] !== null)
                                                <a href="{{ $child['prUrl'] }}" rel="noopener noreferrer">{{ $child['task']->title }}</a>
                                            @else
                                                <span>{{ $child['task']->title }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            <details data-testid="work-detail" class="space-y-3">
                                <summary>{{ __('Details') }}</summary>

                                @foreach ($card['runningItems'] as $running)
                                    <section data-testid="work-running-item" data-machine-source="{{ $running['machineSource'] }}" class="mt-3 border-s-2 ps-3">
                                        <flux:text>{{ $running['item']->role }} · {{ $running['machine'] }}</flux:text>
                                    </section>
                                @endforeach

                                @if ($card['escalations']->isNotEmpty())
                                    <section class="mt-3 space-y-2">
                                        <flux:heading size="sm">{{ __('Open escalations') }}</flux:heading>
                                        @foreach ($card['escalations'] as $escalation)
                                            <pre class="whitespace-pre-wrap break-all">{{ $escalation->question }}</pre>
                                        @endforeach
                                    </section>
                                @endif

                                @if ($card['evidence']->isNotEmpty())
                                    <section class="mt-3 space-y-2">
                                        <flux:heading size="sm">{{ __('Evidence') }}</flux:heading>
                                        <ul class="list-disc space-y-1 ps-5">
                                            @foreach ($card['evidence'] as $evidence)
                                                <li>
                                                    <a href="{{ route('work.evidence.show', ['token' => $evidence->public_token]) }}">{{ $evidence->id }}</a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </section>
                                @endif

                                @if ($card['dependencies']->isNotEmpty())
                                    <section class="mt-3 space-y-2">
                                        <flux:heading size="sm">{{ __('Dependencies') }}</flux:heading>
                                        <ul class="list-disc space-y-1 ps-5">
                                            @foreach ($card['dependencies'] as $dependency)
                                                <li>{{ $dependency->title }}</li>
                                            @endforeach
                                        </ul>
                                    </section>
                                @endif

                                @if ($card['stateOfPlay'] !== null)
                                    <section class="mt-3 space-y-2">
                                        <flux:heading size="sm">{{ __('State of play') }}</flux:heading>
                                        <pre class="whitespace-pre-wrap break-all">{{ $card['stateOfPlay'] }}</pre>
                                    </section>
                                @endif
                            </details>
                        </article>
                    @empty
                        <flux:text>{{ __('No tasks.') }}</flux:text>
                    @endforelse
                </section>
            @endforeach
        </div>
    </section>
</div>
