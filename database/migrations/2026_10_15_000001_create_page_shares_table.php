<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_shares', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('page_id')->constrained('pages')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->foreignId('internal_recipient_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('recipient_email')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['page_id', 'created_at']);
            $table->index(['page_id', 'revoked_at']);
            $table->index(['internal_recipient_user_id', 'revoked_at'], 'page_shares_internal_active_index');
        });

        match (Schema::getConnection()->getDriverName()) {
            'sqlite' => $this->guardSqliteTargets(),
            'mysql', 'mariadb' => $this->guardMysqlTargets(),
            'pgsql' => $this->guardPgsqlTargets(),
            default => null,
        };
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql' && Schema::hasTable('page_shares')) {
            DB::statement('DROP TRIGGER IF EXISTS page_shares_valid_target ON page_shares');
            DB::statement('DROP FUNCTION IF EXISTS page_shares_reject_invalid_target()');
        }

        Schema::dropIfExists('page_shares');
    }

    private function guardSqliteTargets(): void
    {
        foreach (['INSERT', 'UPDATE'] as $operation) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER page_shares_valid_target_{$operation}
                BEFORE {$operation} ON page_shares
                WHEN CASE
                    WHEN NEW.kind = 'internal' THEN NEW.internal_recipient_user_id IS NOT NULL AND NEW.recipient_email IS NULL AND NEW.expires_at IS NULL
                    WHEN NEW.kind = 'external' THEN NEW.internal_recipient_user_id IS NULL AND NEW.recipient_email IS NOT NULL AND NEW.expires_at IS NOT NULL
                    WHEN NEW.kind = 'link' THEN NEW.internal_recipient_user_id IS NULL AND NEW.recipient_email IS NULL AND NEW.expires_at IS NOT NULL
                    ELSE 0
                END != 1
                BEGIN
                    SELECT RAISE(ABORT, 'Invalid page share target');
                END
                SQL);
        }
    }

    private function guardMysqlTargets(): void
    {
        foreach (['INSERT', 'UPDATE'] as $operation) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER page_shares_valid_target_{$operation}
                BEFORE {$operation} ON page_shares
                FOR EACH ROW
                BEGIN
                    IF NOT (
                        (NEW.kind = 'internal' AND NEW.internal_recipient_user_id IS NOT NULL AND NEW.recipient_email IS NULL AND NEW.expires_at IS NULL)
                        OR (NEW.kind = 'external' AND NEW.internal_recipient_user_id IS NULL AND NEW.recipient_email IS NOT NULL AND NEW.expires_at IS NOT NULL)
                        OR (NEW.kind = 'link' AND NEW.internal_recipient_user_id IS NULL AND NEW.recipient_email IS NULL AND NEW.expires_at IS NOT NULL)
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid page share target';
                    END IF;
                END
                SQL);
        }
    }

    private function guardPgsqlTargets(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION page_shares_reject_invalid_target() RETURNS trigger AS $$
            BEGIN
                IF NOT (
                    (NEW.kind = 'internal' AND NEW.internal_recipient_user_id IS NOT NULL AND NEW.recipient_email IS NULL AND NEW.expires_at IS NULL)
                    OR (NEW.kind = 'external' AND NEW.internal_recipient_user_id IS NULL AND NEW.recipient_email IS NOT NULL AND NEW.expires_at IS NOT NULL)
                    OR (NEW.kind = 'link' AND NEW.internal_recipient_user_id IS NULL AND NEW.recipient_email IS NULL AND NEW.expires_at IS NOT NULL)
                ) THEN
                    RAISE EXCEPTION 'Invalid page share target';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER page_shares_valid_target
            BEFORE INSERT OR UPDATE ON page_shares
            FOR EACH ROW EXECUTE FUNCTION page_shares_reject_invalid_target()
            SQL);
    }
};
