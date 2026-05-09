<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_customers', function ($table) {
            $table->foreignId('partner_id')->nullable()->constrained('users')->onDelete('cascade')->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('partner_customers', function ($table) {
            $table->dropForeign(['partner_id']);
            $table->dropColumn('partner_id');
        });
    }
};
