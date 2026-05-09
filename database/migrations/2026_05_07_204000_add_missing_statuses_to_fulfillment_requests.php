<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add missing statuses to the ENUM
        DB::statement("ALTER TABLE fulfillment_requests MODIFY status ENUM(
            'pending', 
            'acknowledged', 
            'awaiting_partner_action', 
            'accepted', 
            'rejected', 
            'assigned', 
            'picking', 
            'packing', 
            'in_progress', 
            'shipping', 
            'ready_for_pickup', 
            'picked_up', 
            'in_transit', 
            'out_for_delivery', 
            'delivered', 
            'failed', 
            'cancelled'
        ) DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE fulfillment_requests MODIFY status ENUM(
            'pending', 
            'acknowledged', 
            'awaiting_partner_action', 
            'accepted', 
            'rejected', 
            'assigned', 
            'in_transit', 
            'delivered', 
            'failed', 
            'cancelled'
        ) DEFAULT 'pending'");
    }
};
