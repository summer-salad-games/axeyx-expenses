<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 120),
                email TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK (length(email) BETWEEN 3 AND 254),
                password TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'viewer' CHECK (role IN ('administrator', 'editor', 'viewer')),
                is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
                remember_token VARCHAR(100),
                auth_version INTEGER NOT NULL DEFAULT 1 CHECK (typeof(auth_version) = 'integer' AND auth_version > 0),
                app_authentication_secret TEXT,
                app_authentication_recovery_codes TEXT,
                created_at DATETIME,
                updated_at DATETIME
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
