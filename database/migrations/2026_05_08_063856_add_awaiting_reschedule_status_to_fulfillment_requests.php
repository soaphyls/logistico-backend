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
            'cancelled',
            'awaiting_reschedule'
        ) NOT NULL DEFAULT 'pending'");
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
        ) NOT NULL DEFAULT 'pending'");
    }
};
