<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_kits', function (Blueprint $table): void {
            $table->string('installation_key', 32)->primary();
            $table->uuid('current_version_id')->nullable()->index();
            $table->unsignedBigInteger('current_version_number')->default(0);
            $table->json('draft')->nullable();
            $table->timestamps();
        });

        Schema::create('brand_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('brand_kit_key', 32);
            $table->unsignedBigInteger('version_number');
            $table->json('configuration');
            $table->string('applied_by_user_id', 64);
            $table->timestamps();

            $table->foreign('brand_kit_key')->references('installation_key')->on('brand_kits')->cascadeOnDelete();
            $table->unique(['brand_kit_key', 'version_number']);
        });

        Schema::create('brand_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->enum('kind', ['logo', 'mark']);
            $table->string('content_type', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('content_hash', 64)->index();
            $table->string('storage_key');
            $table->string('created_by_user_id', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_assets');
        Schema::dropIfExists('brand_versions');
        Schema::dropIfExists('brand_kits');
    }
};
