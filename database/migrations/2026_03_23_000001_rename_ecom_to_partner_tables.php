<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rename tables
        Schema::rename('ecom_modules', 'partner_modules');
        Schema::rename('ecom_customers', 'partner_customers');
        Schema::rename('ecom_products', 'partner_products');

        // Rename columns in fulfillment_requests
        Schema::table('fulfillment_requests', function ($table) {
            $table->renameColumn('ecom_customer_id', 'partner_customer_id');
            $table->renameColumn('ecom_product_id', 'partner_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_requests', function ($table) {
            $table->renameColumn('partner_customer_id', 'ecom_customer_id');
            $table->renameColumn('partner_product_id', 'ecom_product_id');
        });

        Schema::rename('partner_products', 'ecom_products');
        Schema::rename('partner_customers', 'ecom_customers');
        Schema::rename('partner_modules', 'ecom_modules');
    }
};
