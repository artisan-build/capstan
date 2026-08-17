<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spoke_inboxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('spoke_id')->constrained()->cascadeOnDelete();
            $table->string('local_part', 64)->index();
            $table->timestamps();

            $table->unique(['spoke_id', 'local_part']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spoke_inboxes');
    }
};
