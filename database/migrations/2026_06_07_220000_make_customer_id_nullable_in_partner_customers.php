<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_customers', function (Blueprint $table) {
            $table->dropForeign('ecom_customers_customer_id_foreign');
        });

        DB::statement('ALTER TABLE `partner_customers` MODIFY `customer_id` BIGINT UNSIGNED NULL;');

        Schema::table('partner_customers', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('partner_customers', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
        });

        DB::statement('ALTER TABLE `partner_customers` MODIFY `customer_id` BIGINT UNSIGNED NOT NULL;');

        Schema::table('partner_customers', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
        });
    }
};
