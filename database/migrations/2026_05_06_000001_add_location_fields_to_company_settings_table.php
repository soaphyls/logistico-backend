<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('country', 120)->nullable()->after('tracking_prefix');
            $table->string('state', 120)->nullable()->after('country');
            $table->string('city', 120)->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['country', 'state', 'city']);
        });
    }
};

