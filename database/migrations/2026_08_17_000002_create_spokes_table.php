<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spokes', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_id', 64)->index();
            $table->uuid('credential_id')->unique();
            $table->string('name')->nullable();
            $table->timestamp('last_polled_at')->nullable()->index();
            $table->string('last_cursor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spokes');
    }
};
