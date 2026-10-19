<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spokes', function (Blueprint $table): void {
            $table->timestamp('inactive_at')->nullable();
            $table->timestamp('orphaned_at')->nullable();
            $table->index('inactive_at', 'spokes_inactive_at_index');
            $table->index('orphaned_at', 'spokes_orphaned_at_index');
            $table->index(
                ['retired_at', 'orphaned_at', 'inactive_at'],
                'spokes_machine_eligibility_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('spokes', function (Blueprint $table): void {
            $table->dropIndex('spokes_machine_eligibility_index');
            $table->dropIndex('spokes_orphaned_at_index');
            $table->dropIndex('spokes_inactive_at_index');
            $table->dropColumn(['inactive_at', 'orphaned_at']);
        });
    }
};
