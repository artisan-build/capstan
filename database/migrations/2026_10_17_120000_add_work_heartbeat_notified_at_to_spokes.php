<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spokes', function (Blueprint $table): void {
            $table->timestamp('work_heartbeat_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('spokes', function (Blueprint $table): void {
            $table->dropColumn('work_heartbeat_notified_at');
        });
    }
};
