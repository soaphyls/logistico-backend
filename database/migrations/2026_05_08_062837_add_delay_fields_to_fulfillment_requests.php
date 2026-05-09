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
            $table->string('delay_reason')->nullable()->after('fail_reason');
            $table->date('new_delivery_date')->nullable()->after('delay_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fulfillment_requests', function (Blueprint $table) {
            $table->dropColumn(['delay_reason', 'new_delivery_date']);
        });
    }
};
