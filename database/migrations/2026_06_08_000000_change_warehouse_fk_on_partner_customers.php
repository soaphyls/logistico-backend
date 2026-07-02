<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_customers', function ($table) {
            $table->dropForeign('ecom_customers_warehouse_id_foreign');
        });

        DB::statement('ALTER TABLE `partner_customers` MODIFY `warehouse_id` BIGINT UNSIGNED NULL;');

        Schema::table('partner_customers', function ($table) {
            $table->foreign('warehouse_id')
                ->references('id')
                ->on('warehouses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('partner_customers', function ($table) {
            $table->dropForeign(['warehouse_id']);
        });

        DB::statement('UPDATE `partner_customers` SET `warehouse_id` = 0 WHERE `warehouse_id` IS NULL;');
        DB::statement('ALTER TABLE `partner_customers` MODIFY `warehouse_id` BIGINT UNSIGNED NOT NULL;');

        Schema::table('partner_customers', function ($table) {
            $table->foreign('warehouse_id')
                ->references('id')
                ->on('warehouses')
                ->onDelete('cascade');
        });
    }
};
