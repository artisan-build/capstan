<div data-testid="work-board" class="space-y-6">
    <flux:heading size="xl">{{ __('Work') }}</flux:heading>
    <flux:text>{{ __('Latest 25 tasks, up to 25 items per task.') }}</flux:text>
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
