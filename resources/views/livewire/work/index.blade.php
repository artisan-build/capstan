<div data-testid="work-board" class="space-y-6">
    <flux:heading size="xl">{{ __('Work') }}</flux:heading>
    <flux:text>{{ __('Latest 25 tasks, up to 25 items per task.') }}</flux:text>
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
    @foreach ($tasks as $task)
        <section data-testid="work-task" data-task-id="{{ $task->id }}" class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading>{{ $task->title }}</flux:heading>
            <flux:text>{{ $task->team_id }} · {{ $task->creator_actor }}</flux:text>
            @if ($task->cancelled_at)
                <flux:badge>{{ __('Cancelled') }}</flux:badge>
            @endif
            @if ($revision = $task->briefRevisions->first())
                <details data-testid="work-task-brief">
                    <summary>{{ __('Brief') }} · {{ $revision->sequence }}</summary>
                    <pre class="whitespace-pre-wrap break-all">{{ $revision->content }}</pre>
                </details>
            @endif
            @if ($task->steers->isNotEmpty())
                <details data-testid="work-task-steers">
                    <summary>{{ __('Latest steers (up to 10)') }}</summary>
                    @foreach ($task->steers as $steer)
                        <div data-testid="work-steer">
                            <flux:text>{{ $steer->author_actor }} · {{ $steer->work_item_id }}</flux:text>
                            <pre class="whitespace-pre-wrap break-all">{{ $steer->text }}</pre>
                        </div>
                    @endforeach
                </details>
            @endif
            @foreach ($task->items as $item)
                <article data-testid="work-item" data-item-id="{{ $item->id }}" class="space-y-2 border-s-2 ps-4">
                    <flux:heading size="sm">{{ $item->role }}</flux:heading>
                    <flux:badge data-testid="work-item-state">{{ $item->state->value }}</flux:badge>
                    @if ($item->claimant_actor !== null)
                        <flux:text data-testid="work-item-claimant">{{ $item->claimant_actor }} · {{ $item->claimant_spoke_id }}</flux:text>
                    @endif
                    @if ($item->stalled_at)
                        <flux:badge data-testid="work-item-stalled" color="amber">{{ __('Stalled') }}</flux:badge>
                    @endif
                    @if ($item->waiting_on_host_at)
                        <flux:badge data-testid="work-item-waiting" color="amber">{{ __('Waiting on host') }}</flux:badge>
                    @endif
                    @if ($milestone = $item->milestones->first())
                        <div data-testid="work-item-milestone">
                            <flux:text>{{ $milestone->kind->value }}</flux:text>
                            @foreach (['summary', 'url'] as $field)
                                @if (isset($milestone->payload[$field]))
                                    <flux:text>{{ $milestone->payload[$field] }}</flux:text>
                                @endif
                            @endforeach
                            <pre class="whitespace-pre-wrap break-all">{{ json_encode($milestone->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        </div>
                    @endif
                </article>
            @endforeach
            @if ($task->items_count > $task->items->count())
                <flux:text data-testid="work-task-truncated">{{ $task->items_count - $task->items->count() }} {{ __('more items') }}</flux:text>
            @endif
        </section>
    @endforeach
</div>
