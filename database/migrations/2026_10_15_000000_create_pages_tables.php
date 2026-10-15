<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('owner_user_id', 64);
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('refresh_recipe');
            $table->string('layout', 32);
            $table->uuid('latest_version_id')->nullable()->index();
            $table->unsignedBigInteger('version_count')->default(0);
            $table->timestamps();

            $table->index(['owner_user_id', 'updated_at']);
        });

        Schema::create('page_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('page_id')->constrained('pages')->cascadeOnDelete();
            $table->unsignedBigInteger('version_number');
            $table->string('content_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('content_hash', 64)->index();
            $table->string('storage_key');
            $table->string('publisher_user_id', 64);
            $table->string('publisher_source', 32);
            $table->string('publisher_tool', 64);
            $table->text('refresh_recipe');
            $table->string('layout', 32);
            $table->timestamps();

            $table->unique(['page_id', 'version_number']);
            $table->index(['page_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_versions');
        Schema::dropIfExists('pages');
    }
};
