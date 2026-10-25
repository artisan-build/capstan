<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table): void {
            $table->string('amp_project_id')->nullable()->after('handed_off_session_ref');
            $table->string('amp_thread_id')->nullable()->after('amp_project_id');
            $table->index(['amp_project_id', 'amp_thread_id'], 'work_items_amp_claim_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table): void {
            $table->dropIndex('work_items_amp_claim_lookup_index');
            $table->dropColumn(['amp_project_id', 'amp_thread_id']);
        });
    }
};
