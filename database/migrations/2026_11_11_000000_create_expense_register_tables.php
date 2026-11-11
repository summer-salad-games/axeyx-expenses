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
            CREATE TABLE categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 100),
                name_key TEXT NOT NULL UNIQUE CHECK (length(name_key) > 0),
                is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
                created_at DATETIME,
                updated_at DATETIME
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE expenses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                expense_date DATE NOT NULL CHECK (
                    length(expense_date) = 10 AND expense_date GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
                ),
                amount_millimes INTEGER NOT NULL CHECK (
                    typeof(amount_millimes) = 'integer' AND amount_millimes > 0 AND amount_millimes <= 999999999999
                ),
                category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE RESTRICT,
                description TEXT CHECK (description IS NULL OR length(description) <= 5000),
                created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                created_at DATETIME,
                updated_at DATETIME,
                deleted_at DATETIME
            )
            SQL);
        DB::statement('CREATE INDEX expenses_deleted_at_expense_date_index ON expenses (deleted_at, expense_date)');
        DB::statement('CREATE INDEX expenses_category_id_expense_date_index ON expenses (category_id, expense_date)');
        DB::statement('CREATE INDEX expenses_created_by_expense_date_index ON expenses (created_by, expense_date)');

        DB::statement(<<<'SQL'
            CREATE TABLE attachments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                expense_id INTEGER NOT NULL REFERENCES expenses(id) ON DELETE RESTRICT,
                path TEXT NOT NULL UNIQUE CHECK (length(path) > 0),
                original_name TEXT NOT NULL CHECK (length(original_name) BETWEEN 1 AND 255),
                mime_type TEXT NOT NULL,
                size_bytes INTEGER NOT NULL CHECK (typeof(size_bytes) = 'integer' AND size_bytes > 0),
                sha256 TEXT NOT NULL CHECK (length(sha256) = 64 AND sha256 NOT GLOB '*[^0-9a-f]*'),
                uploaded_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                created_at DATETIME,
                updated_at DATETIME
            )
            SQL);
        DB::statement('CREATE INDEX attachments_expense_id_index ON attachments (expense_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE notifications (
                id VARCHAR(36) PRIMARY KEY NOT NULL,
                type VARCHAR(255) NOT NULL,
                notifiable_type VARCHAR(255) NOT NULL,
                notifiable_id INTEGER NOT NULL,
                data TEXT NOT NULL CHECK (json_valid(data)),
                read_at DATETIME,
                created_at DATETIME,
                updated_at DATETIME
            )
            SQL);
        DB::statement('CREATE INDEX notifications_notifiable_type_notifiable_id_index ON notifications (notifiable_type, notifiable_id)');
        DB::statement('CREATE INDEX notifications_recipient_read_at_index ON notifications (notifiable_type, notifiable_id, read_at)');

        DB::statement(<<<'SQL'
            CREATE TABLE audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_id INTEGER REFERENCES users(id) ON DELETE RESTRICT,
                actor_kind TEXT NOT NULL CHECK (actor_kind IN ('user', 'operator', 'system')),
                action TEXT NOT NULL,
                subject_type TEXT NOT NULL,
                subject_id TEXT NOT NULL,
                metadata TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(metadata)),
                created_at DATETIME NOT NULL
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('categories');
    }
};
