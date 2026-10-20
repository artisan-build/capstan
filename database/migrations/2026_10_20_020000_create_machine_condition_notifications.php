<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $ownsNotifications = ! Schema::hasTable('notifications');
        Schema::create('machine_condition_schema_metadata', function (Blueprint $table): void {
            $table->boolean('owns_notifications');
        });
        DB::table('machine_condition_schema_metadata')->insert(['owns_notifications' => $ownsNotifications]);

        if ($ownsNotifications) {
            Schema::create('notifications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::create('machine_condition_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('spoke_id')->constrained()->cascadeOnDelete();
            $table->string('finding_code', 64);
            $table->boolean('active')->default(false);
            $table->char('transition_key', 64)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['spoke_id', 'finding_code'], 'machine_condition_states_machine_code_unique');
            $table->index(['active', 'finding_code'], 'machine_condition_states_active_code_index');
        });

        Schema::table('machine_usage_reports', function (Blueprint $table): void {
            $table->timestamp('unavailable_since')->nullable()->after('last_success_received_at');
        });
    }

    public function down(): void
    {
        $ownsNotifications = Schema::hasTable('machine_condition_schema_metadata')
            && (bool) DB::table('machine_condition_schema_metadata')->value('owns_notifications');
        Schema::table('machine_usage_reports', function (Blueprint $table): void {
            $table->dropColumn('unavailable_since');
        });
        Schema::dropIfExists('machine_condition_states');
        if ($ownsNotifications) {
            Schema::dropIfExists('notifications');
        }
        Schema::dropIfExists('machine_condition_schema_metadata');
    }
};
