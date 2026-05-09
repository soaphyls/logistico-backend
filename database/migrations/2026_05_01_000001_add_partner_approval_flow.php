<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_requests', function (Blueprint $table) {
            $table->decimal('delivery_cost', 12, 2)->nullable()->after('status');
        });

        DB::statement("ALTER TABLE fulfillment_requests MODIFY status ENUM('pending', 'acknowledged', 'awaiting_partner_action', 'accepted', 'rejected', 'assigned', 'in_transit', 'delivered', 'failed', 'cancelled') DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE fulfillment_requests MODIFY status ENUM('pending', 'acknowledged', 'assigned', 'in_transit', 'delivered', 'failed', 'cancelled') DEFAULT 'pending'");
        Schema::table('fulfillment_requests', function (Blueprint $table) {
            $table->dropColumn('delivery_cost');
        });
    }
};