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
        Schema::create('mcp_write_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('owner_subject_type', 64);
            $table->string('owner_subject_ref');
            $table->string('tool', 64);
            $table->string('idempotency_key');
            $table->char('intent_hash', 64);
            $table->string('target_type', 64);
            $table->string('target_id');
            $table->string('state', 32);
            $table->uuid('fence_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->json('refusal_response')->nullable();
            $table->json('terminal_response')->nullable();
            $table->timestamps();

            $table->unique(
                ['owner_subject_type', 'owner_subject_ref', 'tool', 'idempotency_key'],
                'mcp_write_claim_owner_tool_key_unique',
            );
            $table->index(['state', 'lease_expires_at']);
        });

        Schema::create('mcp_write_effects', function (Blueprint $table): void {
            $table->uuid('claim_id')->primary();
            $table->json('accepted_response');
            $table->timestamp('created_at');

            $table->foreign('claim_id')->references('id')->on('mcp_write_claims')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_write_effects');
        Schema::dropIfExists('mcp_write_claims');
    }
};
