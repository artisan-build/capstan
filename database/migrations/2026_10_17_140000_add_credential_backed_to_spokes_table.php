<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spokes', function (Blueprint $table): void {
            $table->boolean('credential_backed')->default(true)->after('credential_id');
        });
    }

    public function down(): void
    {
        Schema::table('spokes', function (Blueprint $table): void {
            $table->dropColumn('credential_backed');
        });
    }
};
