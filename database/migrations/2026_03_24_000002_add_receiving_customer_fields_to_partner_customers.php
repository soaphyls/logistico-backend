<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_customers', function ($table) {
            $table->string('customer_name')->nullable()->after('partner_id');
            $table->string('customer_phone')->nullable()->after('customer_name');
            $table->string('customer_email')->nullable()->after('customer_phone');
            $table->string('customer_address')->nullable()->after('customer_email');
            $table->string('customer_city')->nullable()->after('customer_address');
            $table->string('customer_state')->nullable()->after('customer_city');
            $table->text('customer_notes')->nullable()->after('customer_state');
        });
    }

    public function down(): void
    {
        Schema::table('partner_customers', function ($table) {
            $table->dropColumn([
                'customer_name',
                'customer_phone', 
                'customer_email',
                'customer_address',
                'customer_city',
                'customer_state',
                'customer_notes',
            ]);
        });
    }
};
