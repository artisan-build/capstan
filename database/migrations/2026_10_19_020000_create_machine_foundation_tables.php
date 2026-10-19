<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_usage_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('spoke_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('source', ['openusage-app', 'openusage-sh', 'none']);
            $table->string('source_version', 32)->nullable();
            $table->enum('availability', ['available', 'unavailable']);
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('last_attempt_at');
            $table->timestamp('last_success_received_at')->nullable();
            $table->enum('failure_code', ['not_found', 'timeout', 'incompatible', 'invalid_output'])->nullable();
            $table->timestamps();

            $table->index(['availability', 'last_attempt_at'], 'machine_usage_reports_availability_attempt_index');
            $table->index('last_success_received_at', 'machine_usage_reports_success_index');
        });

        Schema::create('machine_usage_providers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('machine_usage_report_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('plan', 32)->nullable();
            $table->timestamp('fetched_at');
            $table->boolean('source_stale')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->enum('status', ['ok', 'near_limit', 'limited', 'auth_required', 'unsupported', 'error', 'unknown']);
            $table->timestamps();

            $table->unique(['machine_usage_report_id', 'provider'], 'machine_usage_providers_report_provider_unique');
            $table->index(['provider', 'fetched_at'], 'machine_usage_providers_freshness_index');
        });

        Schema::create('machine_usage_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('machine_usage_provider_id')->constrained()->cascadeOnDelete();
            $table->string('slot', 64);
            $table->enum('kind', ['consumption', 'balance']);
            $table->unsignedInteger('window_seconds')->nullable();
            $table->decimal('remaining_percent', 6, 3);
            $table->timestamp('resets_at')->nullable();
            $table->char('window_identity', 64);
            $table->timestamps();

            $table->unique(
                ['machine_usage_provider_id', 'window_identity'],
                'machine_usage_windows_identity_unique',
            );
            $table->index('resets_at', 'machine_usage_windows_resets_at_index');
        });

        Schema::create('machine_team_work_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('spoke_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->json('roles');
            $table->json('capabilities');
            $table->json('role_providers');
            $table->unsignedTinyInteger('concurrency_ceiling')->default(1);
            $table->string('updated_by_actor', 64);
            $table->timestamps();

            $table->unique(['spoke_id', 'team_id'], 'machine_team_work_settings_machine_team_unique');
            $table->index(['team_id', 'enabled'], 'machine_team_work_settings_team_enabled_index');
        });

        Schema::create('machine_work_setting_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('spoke_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->boolean('old_enabled')->nullable();
            $table->json('old_roles')->nullable();
            $table->json('old_capabilities')->nullable();
            $table->json('old_role_providers')->nullable();
            $table->unsignedTinyInteger('old_concurrency_ceiling')->nullable();
            $table->boolean('new_enabled');
            $table->json('new_roles');
            $table->json('new_capabilities');
            $table->json('new_role_providers');
            $table->unsignedTinyInteger('new_concurrency_ceiling');
            $table->string('updated_by_actor', 64);
            $table->timestamp('occurred_at');

            $table->index(
                ['spoke_id', 'team_id', 'occurred_at'],
                'machine_work_setting_changes_machine_team_time_index',
            );
        });

        Schema::create('machine_work_presence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('spoke_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->json('roles');
            $table->json('capabilities');
            $table->json('role_providers');
            $table->unsignedTinyInteger('current_claimed_item_count');
            $table->unsignedTinyInteger('concurrency_ceiling');
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(['spoke_id', 'team_id'], 'machine_work_presence_machine_team_unique');
            $table->index(['team_id', 'observed_at'], 'machine_work_presence_team_observed_index');
        });

        $this->installNumericChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_work_presence');
        Schema::dropIfExists('machine_work_setting_changes');
        Schema::dropIfExists('machine_team_work_settings');
        Schema::dropIfExists('machine_usage_windows');
        Schema::dropIfExists('machine_usage_providers');
        Schema::dropIfExists('machine_usage_reports');
    }

    private function installNumericChecks(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE machine_usage_windows ADD CONSTRAINT machine_usage_windows_values_check CHECK (remaining_percent BETWEEN 0 AND 100 AND ((kind = \'balance\' AND window_seconds IS NULL) OR (kind = \'consumption\' AND window_seconds BETWEEN 1 AND 2678400)))');
            DB::statement('ALTER TABLE machine_team_work_settings ADD CONSTRAINT machine_team_work_settings_ceiling_check CHECK (concurrency_ceiling BETWEEN 1 AND 25)');
            DB::statement('ALTER TABLE machine_work_setting_changes ADD CONSTRAINT machine_work_setting_changes_ceilings_check CHECK ((old_concurrency_ceiling IS NULL OR old_concurrency_ceiling BETWEEN 1 AND 25) AND new_concurrency_ceiling BETWEEN 1 AND 25)');
            DB::statement('ALTER TABLE machine_work_presence ADD CONSTRAINT machine_work_presence_counts_check CHECK (concurrency_ceiling BETWEEN 1 AND 25 AND current_claimed_item_count BETWEEN 0 AND concurrency_ceiling)');

            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        foreach (['INSERT', 'UPDATE'] as $operation) {
            DB::unprepared("CREATE TRIGGER machine_usage_windows_values_{$operation} BEFORE {$operation} ON machine_usage_windows WHEN NOT (NEW.remaining_percent BETWEEN 0 AND 100 AND ((NEW.kind = 'balance' AND NEW.window_seconds IS NULL) OR (NEW.kind = 'consumption' AND NEW.window_seconds BETWEEN 1 AND 2678400))) BEGIN SELECT RAISE(ABORT, 'invalid machine usage window values'); END");
            DB::unprepared("CREATE TRIGGER machine_team_work_settings_ceiling_{$operation} BEFORE {$operation} ON machine_team_work_settings WHEN NOT (NEW.concurrency_ceiling BETWEEN 1 AND 25) BEGIN SELECT RAISE(ABORT, 'invalid machine work setting ceiling'); END");
            DB::unprepared("CREATE TRIGGER machine_work_setting_changes_ceilings_{$operation} BEFORE {$operation} ON machine_work_setting_changes WHEN NOT ((NEW.old_concurrency_ceiling IS NULL OR NEW.old_concurrency_ceiling BETWEEN 1 AND 25) AND NEW.new_concurrency_ceiling BETWEEN 1 AND 25) BEGIN SELECT RAISE(ABORT, 'invalid machine work setting change ceiling'); END");
            DB::unprepared("CREATE TRIGGER machine_work_presence_counts_{$operation} BEFORE {$operation} ON machine_work_presence WHEN NOT (NEW.concurrency_ceiling BETWEEN 1 AND 25 AND NEW.current_claimed_item_count BETWEEN 0 AND NEW.concurrency_ceiling) BEGIN SELECT RAISE(ABORT, 'invalid machine work presence counts'); END");
        }
    }
};
