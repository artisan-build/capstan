<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('type');
            $table->unsignedInteger('version');
            $table->string('from_address');
            $table->string('to_address');
            $table->string('to_local_part', 64);
            $table->string('to_server_id', 26);
            $table->json('body');
            $table->json('refs');
            $table->string('message_id')->unique();
            $table->string('signature', 64);
            $table->string('status')->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->timestamps();

            $table->index(['to_server_id', 'to_local_part', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
