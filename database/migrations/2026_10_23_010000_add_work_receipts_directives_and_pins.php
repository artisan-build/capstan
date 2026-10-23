<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_tasks', function (Blueprint $table): void {
            $table->uuid('brief_page_id')->nullable()->after('workflow_ref');
            $table->uuid('brief_page_version_id')->nullable()->after('brief_page_id');
            $table->string('workflow_sha', 40)->nullable()->after('workflow_ref');
        });

        Schema::table('work_items', function (Blueprint $table): void {
            $table->timestamp('handed_off_at')->nullable()->after('claimed_at');
            $table->text('handed_off_session_ref')->nullable()->after('handed_off_at');
            $table->uuid('launch_nonce')->nullable()->after('handed_off_session_ref');
            $table->timestamp('first_fetched_at')->nullable()->after('launch_nonce');
            $table->string('agent_model', 255)->nullable()->after('first_fetched_at');
            $table->uuid('fetched_page_version_id')->nullable()->after('fetched_brief_revision_id');
            $table->index(['claimant_spoke_id', 'handed_off_at']);
        });

        Schema::create('work_directives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('claimant_spoke_id');
            $table->unsignedBigInteger('fence');
            $table->unsignedTinyInteger('sequence');
            $table->string('kind', 32);
            $table->string('reason', 32);
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->json('outcome')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['work_item_id', 'fence', 'reason', 'sequence']);
            $table->index(['claimant_spoke_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_directives');

        Schema::table('work_items', function (Blueprint $table): void {
            $table->dropIndex(['claimant_spoke_id', 'handed_off_at']);
            $table->dropColumn([
                'handed_off_at', 'handed_off_session_ref', 'launch_nonce', 'first_fetched_at',
                'agent_model', 'fetched_page_version_id',
            ]);
        });

        Schema::table('work_tasks', function (Blueprint $table): void {
            $table->dropColumn(['brief_page_id', 'brief_page_version_id', 'workflow_sha']);
        });
    }
};
