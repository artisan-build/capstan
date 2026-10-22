<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_tasks', function (Blueprint $table): void {
            $table->string('owner_actor')->nullable()->after('creator_actor');
            $table->string('priority', 2)->default('P2')->after('owner_actor');
            $table->boolean('routing_pool_work')->default(false)->after('priority');
            $table->timestamp('expires_at')->nullable()->after('priority');
            $table->string('pr', 2048)->nullable()->after('expires_at');
        });

        DB::table('work_tasks')->whereNull('owner_actor')->update(['owner_actor' => DB::raw('creator_actor')]);
        DB::table('work_tasks')->whereNull('user_actor_address')->update(['routing_pool_work' => true]);

        Schema::table('work_tasks', function (Blueprint $table): void {
            $table->string('owner_actor')->nullable(false)->change();
            $table->index(['team_id', 'owner_actor']);
            $table->index(['priority', 'id']);
            $table->index('expires_at');
        });

        DB::statement("CREATE UNIQUE INDEX work_tasks_one_active_p0_per_owner ON work_tasks (owner_actor) WHERE parent_task_id IS NULL AND priority = 'P0' AND cancelled_at IS NULL");

        Schema::create('work_task_dependencies', function (Blueprint $table): void {
            $table->foreignUuid('work_task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('depends_on_task_id')->constrained('work_tasks')->cascadeOnDelete();
            $table->primary(['work_task_id', 'depends_on_task_id']);
            $table->index('depends_on_task_id');
        });

        Schema::create('work_task_expirations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_task_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('owner_actor');
            $table->string('priority', 2);
            $table->unsignedBigInteger('waited_seconds');
            $table->string('reason', 32);
            $table->timestamp('expired_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_task_expirations');
        Schema::dropIfExists('work_task_dependencies');
        DB::statement('DROP INDEX IF EXISTS work_tasks_one_active_p0_per_owner');

        Schema::table('work_tasks', function (Blueprint $table): void {
            $table->dropIndex(['team_id', 'owner_actor']);
            $table->dropIndex(['priority', 'id']);
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['owner_actor', 'priority', 'routing_pool_work', 'expires_at', 'pr']);
        });
    }
};
