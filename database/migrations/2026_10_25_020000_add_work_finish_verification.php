<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table): void {
            $table->timestamp('work_deadline_at')->nullable()->after('lease_until')->index();
            $table->timestamp('first_deadline_missed_at')->nullable()->after('work_deadline_at');
        });

        Schema::create('work_finishes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('fence');
            $table->text('writeup');
            $table->json('pr');
            $table->string('status', 24)->default('awaiting');
            $table->foreignUuid('orchestration_event_id')->nullable()->constrained('work_orchestration_events')->nullOnDelete();
            $table->string('verdict_actor')->nullable();
            $table->unsignedBigInteger('verdict_spoke_id')->nullable();
            $table->unsignedBigInteger('verdict_seat_fence')->nullable();
            $table->foreignUuid('verdict_turn_item_id')->nullable()->constrained('work_items')->nullOnDelete();
            $table->unsignedBigInteger('verdict_turn_fence')->nullable();
            $table->text('correction_text')->nullable();
            $table->foreignUuid('correction_steer_id')->nullable()->constrained('work_steers')->nullOnDelete();
            $table->foreignUuid('terminal_milestone_id')->nullable()->constrained('work_milestones')->nullOnDelete();
            $table->timestamp('verdict_at')->nullable();
            $table->timestamps();

            $table->index(['work_item_id', 'fence', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_finishes');

        Schema::table('work_items', function (Blueprint $table): void {
            $table->dropColumn(['work_deadline_at', 'first_deadline_missed_at']);
        });
    }
};
