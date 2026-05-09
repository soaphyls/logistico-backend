<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_requests', function (Blueprint $table) {
            $table->text('partner_response')->nullable()->after('fail_reason');
            $table->string('new_delivery_address')->nullable()->after('partner_response');
            $table->string('new_delivery_phone')->nullable()->after('new_delivery_address');
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_requests', function (Blueprint $table) {
            $table->dropColumn(['partner_response', 'new_delivery_address', 'new_delivery_phone']);
        });
    }
};
