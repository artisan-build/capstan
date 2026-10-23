<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('work_item_id')->constrained()->cascadeOnDelete();
            $table->char('public_token', 64)->unique();
            $table->string('storage_key')->unique();
            $table->string('content_type', 64);
            $table->string('extension', 5);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->index(['work_item_id', 'created_at']);
            $table->index(['work_task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_evidence');
    }
};
