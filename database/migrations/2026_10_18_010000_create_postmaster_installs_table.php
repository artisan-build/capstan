<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postmaster_installs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('actor_id', 64);
            $table->char('token_hash', 64)->unique();
            $table->text('script');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['actor_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postmaster_installs');
    }
};
