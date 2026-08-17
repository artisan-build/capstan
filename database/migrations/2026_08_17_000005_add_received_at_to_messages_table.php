<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            // Server-assigned at ingestion; the delivery priority key. The signed,
            // client-supplied created_at is never used for ordering.
            $table->timestamp('received_at')->nullable()->after('acked_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex(['received_at']);
            $table->dropColumn('received_at');
        });
    }
};
