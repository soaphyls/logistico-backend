<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_requests', function ($table) {
            $table->unsignedBigInteger('partner_product_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_requests', function ($table) {
            $table->unsignedBigInteger('partner_product_id')->nullable(false)->change();
        });
    }
};
