<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Accounts are explicitly provisioned through hidden CLI prompts.
        $this->call(DefaultCategorySeeder::class);
    }
}
