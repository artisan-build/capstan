<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_tasks', function (Blueprint $table): void {
            $table->string('escalation_actor')->nullable()->after('owner_actor');
        });
    }

    public function down(): void
    {
        Schema::table('work_tasks', function (Blueprint $table): void {
            $table->dropColumn('escalation_actor');
        });
    }
};
