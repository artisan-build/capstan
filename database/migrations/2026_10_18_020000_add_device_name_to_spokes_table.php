<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spokes', function (Blueprint $table): void {
            $table->string('device_name', 64)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('spokes', function (Blueprint $table): void {
            $table->dropColumn('device_name');
        });
    }
};
