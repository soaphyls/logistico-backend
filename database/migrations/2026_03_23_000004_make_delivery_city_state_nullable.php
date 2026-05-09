<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_requests', function ($table) {
            $table->string('delivery_city')->nullable()->change();
            $table->string('delivery_state')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_requests', function ($table) {
            $table->string('delivery_city')->nullable(false)->default('')->change();
            $table->string('delivery_state')->nullable(false)->default('')->change();
        });
    }
};
