<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('postmaster_installs', function (Blueprint $table): void {
            $table->uuid('credential_authorization_id')->nullable()->unique()->after('actor_id');
            $table->foreignId('team_id')->nullable()->after('credential_authorization_id')->constrained()->restrictOnDelete();
            $table->boolean('work_enabled')->nullable()->after('team_id');
            $table->json('work_roles')->nullable()->after('work_enabled');
            $table->json('work_capabilities')->nullable()->after('work_roles');
            $table->json('work_role_providers')->nullable()->after('work_capabilities');
            $table->unsignedTinyInteger('work_concurrency_ceiling')->nullable()->after('work_role_providers');
            $table->timestamp('applied_at')->nullable()->after('used_at');
        });
    }

    public function down(): void
    {
        Schema::table('postmaster_installs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('team_id');
            $table->dropUnique(['credential_authorization_id']);
            $table->dropColumn([
                'credential_authorization_id',
                'work_enabled',
                'work_roles',
                'work_capabilities',
                'work_role_providers',
                'work_concurrency_ceiling',
                'applied_at',
            ]);
        });
    }
};
