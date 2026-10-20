<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_work_presence', function (Blueprint $table): void {
            $table->unsignedTinyInteger('available_capacity')->default(0)->after('current_claimed_item_count');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE machine_work_presence ADD CONSTRAINT machine_work_presence_available_capacity_check CHECK (NOT (concurrency_ceiling BETWEEN 1 AND 25 AND current_claimed_item_count BETWEEN 0 AND concurrency_ceiling) OR available_capacity BETWEEN 0 AND concurrency_ceiling - current_claimed_item_count)');

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("CREATE TRIGGER machine_work_presence_available_capacity_{$operation} BEFORE {$operation} ON machine_work_presence WHEN NEW.concurrency_ceiling BETWEEN 1 AND 25 AND NEW.current_claimed_item_count BETWEEN 0 AND NEW.concurrency_ceiling AND NOT (NEW.available_capacity BETWEEN 0 AND NEW.concurrency_ceiling - NEW.current_claimed_item_count) BEGIN SELECT RAISE(ABORT, 'invalid machine work presence availability'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE machine_work_presence DROP CONSTRAINT IF EXISTS machine_work_presence_available_capacity_check');
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS machine_work_presence_available_capacity_{$operation}");
            }
        }

        Schema::table('machine_work_presence', function (Blueprint $table): void {
            $table->dropColumn('available_capacity');
        });
    }
};
