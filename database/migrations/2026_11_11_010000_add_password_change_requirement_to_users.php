<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 1 CHECK (must_change_password IN (0, 1))');

        // An existing user's latest audited password change may already be their own choice.
        DB::statement(<<<'SQL'
            UPDATE users SET must_change_password = 0 WHERE id IN (
                SELECT a.actor_id FROM audit_logs a
                WHERE a.subject_type = 'App\Models\User'
                  AND a.subject_id = CAST(a.actor_id AS TEXT)
                  AND a.actor_kind = 'user'
                  AND a.action = 'account.password_changed'
                  AND a.id = (
                      SELECT MAX(b.id) FROM audit_logs b
                      WHERE b.subject_type = a.subject_type AND b.subject_id = a.subject_id
                        AND b.action = 'account.password_changed'
                  )
            )
            SQL);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('must_change_password'));
    }
};
