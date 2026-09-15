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
        Schema::create('artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('actor_id', 64);
            $table->string('visibility');
            $table->timestamp('expires_at')->nullable();
            $table->string('content_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('content_hash', 64);
            $table->string('storage_key');
            $table->timestamps();

            $table->index(['actor_id', 'created_at']);
            $table->index(['visibility', 'expires_at']);
            $table->index('content_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artifacts');
    }
};
