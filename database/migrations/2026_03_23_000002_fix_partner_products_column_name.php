<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fix: rename ecom_customer_id column in partner_products to partner_customer_id
        if (Schema::hasColumn('partner_products', 'ecom_customer_id')) {
            Schema::table('partner_products', function ($table) {
                $table->renameColumn('ecom_customer_id', 'partner_customer_id');
            });
        }

        // Fix: rename ecom_customer_id column in partner_customers if it exists (shouldn't, but just in case)
        if (Schema::hasColumn('partner_customers', 'ecom_customer_id')) {
            Schema::table('partner_customers', function ($table) {
                $table->renameColumn('ecom_customer_id', 'partner_customer_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('partner_products', 'partner_customer_id')) {
            Schema::table('partner_products', function ($table) {
                $table->renameColumn('partner_customer_id', 'ecom_customer_id');
            });
        }
    }
};
