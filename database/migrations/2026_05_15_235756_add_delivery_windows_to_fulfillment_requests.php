<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fulfillment_requests', function (Blueprint $table) {
            $table->date('preferred_delivery_date')->nullable()->after('status');
            $table->string('preferred_delivery_time_window')->nullable()->after('preferred_delivery_date');
            $table->timestamp('delivery_confirmed_at')->nullable()->after('preferred_delivery_time_window');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fulfillment_requests', function (Blueprint $table) {
            $table->dropColumn([
                'preferred_delivery_date',
                'preferred_delivery_time_window',
                'delivery_confirmed_at'
            ]);
        });
    }
};
