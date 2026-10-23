<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table): void {
            $table->timestamp('release_requested_at')->nullable();
            $table->timestamp('release_deadline')->nullable()->index();
            $table->uuid('release_for_item_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table): void {
            $table->dropUnique(['release_for_item_id']);
            $table->dropIndex(['release_deadline']);
            $table->dropColumn(['release_requested_at', 'release_deadline', 'release_for_item_id']);
        });
    }
};
