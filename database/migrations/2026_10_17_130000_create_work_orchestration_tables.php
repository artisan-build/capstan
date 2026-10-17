<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orchestrator_seats', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_task_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('holder_actor')->nullable();
            $table->unsignedBigInteger('holder_spoke_id')->nullable();
            $table->text('session_ref')->nullable();
            $table->boolean('interactive')->default(false);
            $table->timestamp('lease_until')->nullable()->index();
            $table->unsignedBigInteger('fence')->default(0);
            $table->boolean('failover')->default(true);
            $table->timestamp('waiting_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            // The unique parent row is the portable active-turn slot and sequence allocator.
            $table->foreignUuid('active_item_id')->nullable()->constrained('work_items')->nullOnDelete();
            $table->unsignedBigInteger('event_sequence')->default(0);
            $table->unsignedBigInteger('completed_cursor')->default(0);
            $table->unsignedBigInteger('turns_completed')->default(0);
            $table->unsignedBigInteger('turns_started')->default(0);
            $table->unsignedBigInteger('children_created')->default(0);
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();
        });
        Schema::table('work_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('turn_cursor')->nullable();
            $table->unsignedBigInteger('turn_upper_bound')->nullable();
            $table->unsignedBigInteger('seat_fence')->nullable();
            $table->string('addressed_actor')->nullable();
        });
        Schema::create('work_orchestration_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_task_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('source_key');
            $table->string('kind', 64);
            $table->json('evidence');
            $table->uuid('author_task_id')->nullable();
            $table->boolean('wakes');
            $table->timestamps();
            $table->unique(['work_task_id', 'sequence']);
            $table->unique(['work_task_id', 'source_key']);
        });
        Schema::create('work_orchestration_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('triggering_event_id')->constrained('work_orchestration_events');
            $table->text('decision');
            $table->text('rationale');
            $table->json('evidence');
            $table->timestamps();
        });
        Schema::create('work_state_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('work_item_id')->unique()->constrained();
            $table->unsignedBigInteger('sequence');
            $table->unsignedBigInteger('through_cursor');
            $table->text('content');
            $table->timestamps();
            $table->unique(['work_task_id', 'sequence']);
        });
        Schema::create('work_wakes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_task_id')->constrained()->cascadeOnDelete();
            $table->string('request_key');
            $table->timestamp('due_at')->index();
            $table->timestamp('fired_at')->nullable();
            $table->text('reason');
            $table->timestamps();
            $table->unique(['work_task_id', 'request_key']);
        });
        Schema::create('work_escalations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('work_item_id')->unique()->constrained();
            $table->string('request_key');
            $table->string('addressed_actor');
            $table->text('question');
            $table->json('metadata');
            $table->text('answer')->nullable();
            $table->string('answered_by')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['work_task_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_escalations');
        Schema::dropIfExists('work_wakes');
        Schema::dropIfExists('work_state_revisions');
        Schema::dropIfExists('work_orchestration_decisions');
        Schema::dropIfExists('work_orchestration_events');
        Schema::dropIfExists('work_orchestrator_seats');
        Schema::table('work_items', fn (Blueprint $table) => $table->dropColumn(['turn_cursor', 'turn_upper_bound', 'seat_fence', 'addressed_actor']));
    }
};
