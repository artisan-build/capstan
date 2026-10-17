<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->boolean('pool_work')->default(false);
            $table->boolean('default_failover')->default(true);
        });

        Schema::create('work_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('user_actor_address')->nullable();
            $table->string('device_address')->nullable();
            $table->boolean('failover')->nullable();
            $table->json('required_capabilities');
            $table->unsignedInteger('timebox_minutes');
            $table->string('creator_actor');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('parent_task_id')->nullable()->constrained('work_tasks')->nullOnDelete();
            $table->uuid('workflow_id')->nullable()->index();
            $table->timestamps();

            $table->index(['team_id', 'created_at']);
        });

        Schema::create('work_brief_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_task_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->text('content');
            $table->string('author_actor');
            $table->timestamps();

            $table->unique(['work_task_id', 'sequence']);
        });

        Schema::create('work_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_task_id')->constrained()->cascadeOnDelete();
            $table->string('role', 64);
            $table->string('state', 32);
            $table->string('claimant_spoke_address')->nullable();
            $table->string('claimant_actor')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->unsignedBigInteger('fence')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('fetched_brief_revision')->nullable();
            $table->string('last_progress', 2000)->nullable();
            $table->timestamp('stalled_at')->nullable();
            $table->timestamp('host_wait_started_at')->nullable();
            $table->timestamp('host_wait_until')->nullable();
            $table->timestamps();

            $table->index(['work_task_id', 'state']);
            $table->index(['state', 'lease_until']);
        });

        Schema::create('work_item_transitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_item_id')->constrained()->cascadeOnDelete();
            $table->string('from_state', 32);
            $table->string('to_state', 32);
            $table->string('actor_kind', 32);
            $table->string('actor_id');
            $table->unsignedBigInteger('fence');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['work_item_id', 'occurred_at']);
        });

        Schema::create('work_milestones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('work_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 32);
            $table->json('payload');
            $table->string('author_actor');
            $table->timestamps();

            $table->index(['work_task_id', 'created_at']);
        });

        Schema::create('work_steers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('work_item_id')->nullable()->constrained()->nullOnDelete();
            $table->text('text');
            $table->string('author_actor');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['work_task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_steers');
        Schema::dropIfExists('work_milestones');
        Schema::dropIfExists('work_item_transitions');
        Schema::dropIfExists('work_items');
        Schema::dropIfExists('work_brief_revisions');
        Schema::dropIfExists('work_tasks');

        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn(['pool_work', 'default_failover']);
        });
    }
};
