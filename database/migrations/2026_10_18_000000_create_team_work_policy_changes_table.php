<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_work_policy_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // The actor is kept as a plain reference so the record outlives the user.
            $table->string('actor');
            $table->string('surface', 16);
            $table->boolean('old_pool_work');
            $table->boolean('new_pool_work');
            $table->timestamp('created_at');

            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_work_policy_changes');
    }
};
