<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const array DIRECT_TEAM_TABLES = [
        'work_tasks' => 'team_id',
        'machine_work_presence' => 'team_id',
        'machine_team_work_settings' => 'team_id',
    ];

    /** @var array<string, string> */
    private const array TASK_TABLES = [
        'work_items' => 'work_task_id',
        'work_task_dependencies' => 'work_task_id',
        'work_task_expirations' => 'work_task_id',
    ];

    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->unsignedBigInteger('work_preemption_revision')->default(0);
        });

        match (DB::getDriverName()) {
            'pgsql' => $this->installPostgresGuards(),
            'sqlite' => $this->installSqliteGuards(),
            default => null,
        };
    }

    public function down(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => $this->dropPostgresGuards(),
            'sqlite' => $this->dropSqliteGuards(),
            default => null,
        };

        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn('work_preemption_revision');
        });
    }

    private function installPostgresGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION capstan_guard_work_preemption_direct() RETURNS trigger AS $$
            DECLARE
                row_data jsonb;
                target_team_id bigint;
            BEGIN
                IF TG_OP = 'DELETE' THEN row_data := to_jsonb(OLD); ELSE row_data := to_jsonb(NEW); END IF;
                target_team_id := (row_data ->> TG_ARGV[0])::bigint;
                UPDATE teams SET work_preemption_revision = work_preemption_revision + 1 WHERE id = target_team_id;
                IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION capstan_guard_work_preemption_task() RETURNS trigger AS $$
            DECLARE
                row_data jsonb;
                target_task_id uuid;
            BEGIN
                IF TG_OP = 'DELETE' THEN row_data := to_jsonb(OLD); ELSE row_data := to_jsonb(NEW); END IF;
                target_task_id := (row_data ->> TG_ARGV[0])::uuid;
                UPDATE teams SET work_preemption_revision = work_preemption_revision + 1
                WHERE id = (SELECT team_id FROM work_tasks WHERE id = target_task_id);
                IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION capstan_guard_work_preemption_item() RETURNS trigger AS $$
            DECLARE
                row_data jsonb;
                target_item_id uuid;
            BEGIN
                IF TG_OP = 'DELETE' THEN row_data := to_jsonb(OLD); ELSE row_data := to_jsonb(NEW); END IF;
                target_item_id := (row_data ->> TG_ARGV[0])::uuid;
                UPDATE teams SET work_preemption_revision = work_preemption_revision + 1
                WHERE id = (
                    SELECT work_tasks.team_id FROM work_items
                    JOIN work_tasks ON work_tasks.id = work_items.work_task_id
                    WHERE work_items.id = target_item_id
                );
                IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION capstan_guard_work_preemption_machine() RETURNS trigger AS $$
            DECLARE
                row_data jsonb;
                target_spoke_id bigint;
                target_actor_id text;
            BEGIN
                IF TG_OP = 'DELETE' THEN row_data := to_jsonb(OLD); ELSE row_data := to_jsonb(NEW); END IF;
                CASE TG_ARGV[0]
                    WHEN 'spoke' THEN target_spoke_id := (row_data ->> 'id')::bigint;
                    WHEN 'report' THEN target_spoke_id := (row_data ->> 'spoke_id')::bigint;
                    WHEN 'provider' THEN
                        SELECT spoke_id INTO target_spoke_id FROM machine_usage_reports
                        WHERE id = (row_data ->> 'machine_usage_report_id')::bigint;
                    WHEN 'window' THEN
                        SELECT machine_usage_reports.spoke_id INTO target_spoke_id
                        FROM machine_usage_providers
                        JOIN machine_usage_reports ON machine_usage_reports.id = machine_usage_providers.machine_usage_report_id
                        WHERE machine_usage_providers.id = (row_data ->> 'machine_usage_provider_id')::bigint;
                    WHEN 'user' THEN target_actor_id := row_data ->> 'id';
                    WHEN 'offboarded' THEN target_actor_id := row_data ->> 'user_id';
                END CASE;

                IF target_spoke_id IS NOT NULL THEN
                    UPDATE teams SET work_preemption_revision = work_preemption_revision + 1 WHERE id IN (
                        SELECT team_id FROM machine_work_presence WHERE spoke_id = target_spoke_id
                        UNION
                        SELECT team_id FROM machine_team_work_settings WHERE spoke_id = target_spoke_id
                    );
                ELSIF target_actor_id IS NOT NULL THEN
                    UPDATE teams SET work_preemption_revision = work_preemption_revision + 1 WHERE id IN (
                        SELECT machine_work_presence.team_id FROM machine_work_presence
                        JOIN spokes ON spokes.id = machine_work_presence.spoke_id
                        WHERE spokes.actor_id = target_actor_id
                        UNION
                        SELECT machine_team_work_settings.team_id FROM machine_team_work_settings
                        JOIN spokes ON spokes.id = machine_team_work_settings.spoke_id
                        WHERE spokes.actor_id = target_actor_id
                    );
                END IF;

                IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        foreach (self::DIRECT_TEAM_TABLES as $table => $column) {
            DB::statement("CREATE TRIGGER work_preemption_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION capstan_guard_work_preemption_direct('{$column}')");
        }
        foreach (self::TASK_TABLES as $table => $column) {
            DB::statement("CREATE TRIGGER work_preemption_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION capstan_guard_work_preemption_task('{$column}')");
        }
        DB::statement("CREATE TRIGGER work_preemption_guard BEFORE INSERT OR UPDATE OR DELETE ON work_milestones FOR EACH ROW EXECUTE FUNCTION capstan_guard_work_preemption_item('work_item_id')");
        foreach ([
            'spokes' => 'spoke',
            'machine_usage_reports' => 'report',
            'machine_usage_providers' => 'provider',
            'machine_usage_windows' => 'window',
            'users' => 'user',
            'offboarded_subjects' => 'offboarded',
        ] as $table => $mode) {
            DB::statement("CREATE TRIGGER work_preemption_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION capstan_guard_work_preemption_machine('{$mode}')");
        }
    }

    private function installSqliteGuards(): void
    {
        foreach (['INSERT' => 'NEW', 'UPDATE' => 'NEW', 'DELETE' => 'OLD'] as $operation => $row) {
            $suffix = strtolower($operation);
            foreach (self::DIRECT_TEAM_TABLES as $table => $column) {
                DB::unprepared("CREATE TRIGGER work_preemption_guard_{$table}_{$suffix} BEFORE {$operation} ON {$table} BEGIN UPDATE teams SET work_preemption_revision = work_preemption_revision + 1 WHERE id = {$row}.{$column}; END");
            }
            foreach (self::TASK_TABLES as $table => $column) {
                DB::unprepared("CREATE TRIGGER work_preemption_guard_{$table}_{$suffix} BEFORE {$operation} ON {$table} BEGIN UPDATE teams SET work_preemption_revision = work_preemption_revision + 1 WHERE id = (SELECT team_id FROM work_tasks WHERE id = {$row}.{$column}); END");
            }
            DB::unprepared("CREATE TRIGGER work_preemption_guard_work_milestones_{$suffix} BEFORE {$operation} ON work_milestones BEGIN UPDATE teams SET work_preemption_revision = work_preemption_revision + 1 WHERE id = (SELECT work_tasks.team_id FROM work_items JOIN work_tasks ON work_tasks.id = work_items.work_task_id WHERE work_items.id = {$row}.work_item_id); END");
            DB::unprepared("CREATE TRIGGER work_preemption_guard_spokes_{$suffix} BEFORE {$operation} ON spokes BEGIN UPDATE teams SET work_preemption_revision = work_preemption_revision + 1 WHERE id IN (SELECT team_id FROM machine_work_presence WHERE spoke_id = {$row}.id UNION SELECT team_id FROM machine_team_work_settings WHERE spoke_id = {$row}.id); END");
            DB::unprepared("CREATE TRIGGER work_preemption_guard_machine_usage_reports_{$suffix} BEFORE {$operation} ON machine_usage_reports BEGIN UPDATE teams SET work_preemption_revision = work_preemption_revision + 1 WHERE id IN (SELECT team_id FROM machine_work_presence WHERE spoke_id = {$row}.spoke_id UNION SELECT team_id FROM machine_team_work_settings WHERE spoke_id = {$row}.spoke_id); END");
            DB::unprepared("CREATE TRIGGER work_preemption_guard_machine_usage_providers_{$suffix} BEFORE {$operation} ON machine_usage_providers BEGIN UPDATE teams SET work_preemption_revision = work_preemption_revision + 1 WHERE id IN (SELECT team_id FROM machine_work_presence WHERE spoke_id = (SELECT spoke_id FROM machine_usage_reports WHERE id = {$row}.machine_usage_report_id) UNION SELECT team_id FROM machine_team_work_settings WHERE spoke_id = (SELECT spoke_id FROM machine_usage_reports WHERE id = {$row}.machine_usage_report_id)); END");
            DB::unprepared("CREATE TRIGGER work_preemption_guard_machine_usage_windows_{$suffix} BEFORE {$operation} ON machine_usage_windows BEGIN UPDATE teams SET work_preemption_revision = work_preemption_revision + 1 WHERE id IN (SELECT team_id FROM machine_work_presence WHERE spoke_id = (SELECT machine_usage_reports.spoke_id FROM machine_usage_providers JOIN machine_usage_reports ON machine_usage_reports.id = machine_usage_providers.machine_usage_report_id WHERE machine_usage_providers.id = {$row}.machine_usage_provider_id) UNION SELECT team_id FROM machine_team_work_settings WHERE spoke_id = (SELECT machine_usage_reports.spoke_id FROM machine_usage_providers JOIN machine_usage_reports ON machine_usage_reports.id = machine_usage_providers.machine_usage_report_id WHERE machine_usage_providers.id = {$row}.machine_usage_provider_id)); END");
            foreach (['users' => 'id', 'offboarded_subjects' => 'user_id'] as $table => $column) {
                DB::unprepared("CREATE TRIGGER work_preemption_guard_{$table}_{$suffix} BEFORE {$operation} ON {$table} BEGIN UPDATE teams SET work_preemption_revision = work_preemption_revision + 1 WHERE id IN (SELECT machine_work_presence.team_id FROM machine_work_presence JOIN spokes ON spokes.id = machine_work_presence.spoke_id WHERE spokes.actor_id = CAST({$row}.{$column} AS TEXT) UNION SELECT machine_team_work_settings.team_id FROM machine_team_work_settings JOIN spokes ON spokes.id = machine_team_work_settings.spoke_id WHERE spokes.actor_id = CAST({$row}.{$column} AS TEXT)); END");
            }
        }
    }

    private function dropPostgresGuards(): void
    {
        foreach ([...array_keys(self::DIRECT_TEAM_TABLES), ...array_keys(self::TASK_TABLES), 'work_milestones', 'spokes', 'machine_usage_reports', 'machine_usage_providers', 'machine_usage_windows', 'users', 'offboarded_subjects'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS work_preemption_guard ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS capstan_guard_work_preemption_machine()');
        DB::statement('DROP FUNCTION IF EXISTS capstan_guard_work_preemption_item()');
        DB::statement('DROP FUNCTION IF EXISTS capstan_guard_work_preemption_task()');
        DB::statement('DROP FUNCTION IF EXISTS capstan_guard_work_preemption_direct()');
    }

    private function dropSqliteGuards(): void
    {
        foreach ([...array_keys(self::DIRECT_TEAM_TABLES), ...array_keys(self::TASK_TABLES), 'work_milestones', 'spokes', 'machine_usage_reports', 'machine_usage_providers', 'machine_usage_windows', 'users', 'offboarded_subjects'] as $table) {
            foreach (['insert', 'update', 'delete'] as $operation) {
                DB::statement("DROP TRIGGER IF EXISTS work_preemption_guard_{$table}_{$operation}");
            }
        }
    }
};
