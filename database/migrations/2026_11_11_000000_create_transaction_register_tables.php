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
                type TEXT NOT NULL DEFAULT 'expense' CHECK (type IN ('expense', 'revenue')),
                name_key TEXT NOT NULL CHECK (length(name_key) > 0),
                is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
                created_at DATETIME,
                updated_at DATETIME,
                UNIQUE(type, name_key),
                UNIQUE(id, type)
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL CHECK (type IN ('expense', 'revenue')),
                transaction_date DATE NOT NULL CHECK (
                    length(transaction_date) = 10 AND transaction_date GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
                ),
                amount_millimes INTEGER NOT NULL CHECK (
                    typeof(amount_millimes) = 'integer' AND amount_millimes > 0 AND amount_millimes <= 999999999999
                ),
                category_id INTEGER NOT NULL,
                description TEXT CHECK (description IS NULL OR length(description) <= 5000),
                created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                created_at DATETIME,
                updated_at DATETIME,
                FOREIGN KEY(category_id, type) REFERENCES categories(id, type) ON DELETE RESTRICT ON UPDATE RESTRICT
            )
            SQL);
        DB::statement('CREATE INDEX transactions_date_index ON transactions (transaction_date)');
        DB::statement('CREATE INDEX transactions_type_date_index ON transactions (type, transaction_date)');
        DB::statement('CREATE INDEX transactions_category_id_transaction_date_index ON transactions (category_id, transaction_date)');
        DB::statement('CREATE INDEX transactions_created_by_transaction_date_index ON transactions (created_by, transaction_date)');

        DB::statement(<<<'SQL'
            CREATE TABLE attachments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                transaction_id INTEGER NOT NULL REFERENCES transactions(id) ON DELETE RESTRICT,
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
        DB::statement('CREATE INDEX attachments_transaction_id_index ON attachments (transaction_id)');

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
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('categories');
    }
};
