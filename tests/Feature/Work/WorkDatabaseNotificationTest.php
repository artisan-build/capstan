<?php

use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkEscalation;
use App\Models\WorkItem;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkTask;
use App\Notifications\WorkNotification;
use App\Work\WorkNotifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

test('every Work finding logs and stores one bounded database notification for its owning person', function (): void {
    $owner = capstanUser();
    $addressed = capstanUser();
    $newOwner = capstanUser();
    $other = capstanUser();
    $task = WorkTask::query()->create([
        'team_id' => Team::default()->id,
        'title' => 'Notification task',
        'required_capabilities' => [],
        'timebox_minutes' => 30,
        'creator_actor' => (string) $owner->id,
        'owner_actor' => (string) $owner->id,
    ]);
    $item = $task->items()->create(['role' => 'builder', 'fence' => 7]);
    $human = $task->items()->create(['role' => WorkItem::ROLE_HUMAN, 'addressed_actor' => (string) $addressed->id]);
    $escalation = WorkEscalation::query()->create([
        'work_task_id' => $task->id,
        'work_item_id' => $human->id,
        'request_key' => 'notification',
        'addressed_actor' => (string) $addressed->id,
        'question' => 'Sensitive question',
        'metadata' => ['options' => ['Sensitive option'], 'recommendation' => 'Sensitive recommendation'],
    ]);
    $spoke = Spoke::query()->create([
        'actor_id' => (string) $owner->id,
        'credential_id' => (string) Str::uuid7(),
    ]);
    $seat = WorkOrchestratorSeat::query()->create(['work_task_id' => $task->id]);
    Log::spy();
    $notifier = resolve(WorkNotifier::class);

    $notifier->escalated($escalation);
    $notifier->stalled($item);
    $notifier->missedHeartbeat($spoke);
    $notifier->waitingOnHost($item);
    $notifier->seatWaiting($seat);
    $notifier->reassigned($task, (string) $owner->id, (string) $newOwner->id, 'move');

    expect($addressed->fresh()->notifications)->toHaveCount(1)
        ->and($newOwner->fresh()->notifications)->toHaveCount(1)
        ->and($owner->fresh()->notifications)->toHaveCount(4)
        ->and($other->fresh()->notifications)->toBeEmpty();
    $notifications = $addressed->fresh()->notifications
        ->concat($newOwner->fresh()->notifications)
        ->concat($owner->fresh()->notifications);
    expect($notifications)->toHaveCount(6);
    foreach ($notifications as $notification) {
        expect($notification->type)->toBe(WorkNotification::class);
        foreach (['brief', 'question', 'answer', 'note', 'credential', 'session_ref', 'path'] as $excluded) {
            expect($notification->data)->not->toHaveKey($excluded)
                ->and(json_encode($notification->data, JSON_THROW_ON_ERROR))->not->toContain('Sensitive');
        }
    }
    expect($notifications->pluck('data.kind')->sort()->values()->all())->toBe([
        'escalation_opened',
        'host_missed_heartbeat',
        'item_stalled',
        'item_waiting_on_host',
        'seat_waiting',
        'task_reassigned',
    ]);

    foreach ([
        'Work escalation requires an answer.',
        'Work item stalled.',
        'Work host missed heartbeat.',
        'Work item waiting on pinned host.',
        'Work orchestrator waiting on pinned host.',
        'Work task reassigned.',
    ] as $message) {
        Log::shouldHaveReceived('warning')->with($message, Mockery::type('array'))->once();
    }
});
