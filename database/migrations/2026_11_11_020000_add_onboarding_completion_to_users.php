<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->timestamp('onboarding_completed_at')->nullable());
        DB::table('users')->where('must_change_password', false)->whereNotNull('app_authentication_secret')
            ->update(['onboarding_completed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('onboarding_completed_at'));
    }
};
