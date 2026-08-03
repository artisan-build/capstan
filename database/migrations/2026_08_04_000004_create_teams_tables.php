<?php

use App\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        $now = now();

        DB::table('teams')->insert([
            'name' => 'Default',
            'slug' => Team::DEFAULT_SLUG,
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::create('team_user', function (Blueprint $table) {
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['team_id', 'user_id']);
            $table->index('user_id');
        });

        $defaultTeamId = DB::table('teams')->where('slug', Team::DEFAULT_SLUG)->value('id');

        DB::table('team_user')->insertUsing(
            ['team_id', 'user_id', 'created_at', 'updated_at'],
            DB::table('users')->selectRaw('? as team_id, id as user_id, ? as created_at, ? as updated_at', [
                $defaultTeamId,
                $now,
                $now,
            ]),
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
