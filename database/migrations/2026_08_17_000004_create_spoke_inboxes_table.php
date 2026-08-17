<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Routing: which of its owner's inboxes a spoke is currently ready to receive for.
        Schema::create('spoke_inboxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('spoke_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['spoke_id', 'inbox_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spoke_inboxes');
    }
};
