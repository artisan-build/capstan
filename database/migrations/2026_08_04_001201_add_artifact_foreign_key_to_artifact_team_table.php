<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('artifact_team', function (Blueprint $table) {
            $table->foreign('artifact_id')->references('id')->on('artifacts')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('artifact_team', function (Blueprint $table) {
            $table->dropForeign(['artifact_id']);
        });
    }
};
