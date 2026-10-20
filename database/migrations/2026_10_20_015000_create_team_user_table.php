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
        });

        $defaultTeamId = DB::table('teams')->where('is_default', true)->value('id');
        if (is_int($defaultTeamId)) {
            $now = now();
            DB::table('team_user')->insertOrIgnore(DB::table('users')->pluck('id')->map(fn (int $userId): array => [
                'team_id' => $defaultTeamId,
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('team_user');
    }
};
