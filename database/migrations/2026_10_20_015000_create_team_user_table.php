<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_user', function (Blueprint $table): void {
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['team_id', 'user_id']);
            $table->index(['user_id', 'team_id'], 'team_user_user_team_index');
        });

        $defaultTeamId = DB::table('teams')->where('is_default', true)->value('id');
        if (is_int($defaultTeamId)) {
            $now = now();
            DB::table('team_user')->insertUsing(
                ['team_id', 'user_id', 'created_at', 'updated_at'],
                DB::table('users')->selectRaw('? as team_id, id, ? as created_at, ? as updated_at', [
                    $defaultTeamId, $now, $now,
                ]),
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('team_user');
    }
};
