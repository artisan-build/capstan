<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spokes', function (Blueprint $table): void {
            $table->string('probe_status')->default('unknown');
            $table->timestamp('probe_failed_at')->nullable();
        });

        Schema::create('spoke_probes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('spoke_id')->constrained()->cascadeOnDelete();
            $table->string('probe_id', 26)->unique();
            $table->string('nonce', 43);
            $table->string('status');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['spoke_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spoke_probes');

        Schema::table('spokes', function (Blueprint $table): void {
            $table->dropColumn(['probe_status', 'probe_failed_at']);
        });
    }
};
