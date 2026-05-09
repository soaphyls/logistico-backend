<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // First, expand the enum to include ALL old AND new values
        DB::statement("ALTER TABLE fulfillment_requests MODIFY COLUMN status ENUM('pending', 'processing', 'picked', 'acknowledged', 'out_for_delivery', 'delivered', 'failed', 'cancelled') DEFAULT 'pending'");

        // Now update old statuses to new ones
        DB::table('fulfillment_requests')
            ->whereIn('status', ['picked', 'processing'])
            ->update(['status' => 'acknowledged']);

        // Finally, remove old enum values
        DB::statement("ALTER TABLE fulfillment_requests MODIFY COLUMN status ENUM('pending', 'acknowledged', 'out_for_delivery', 'delivered', 'failed', 'cancelled') DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE fulfillment_requests MODIFY COLUMN status ENUM('pending', 'processing', 'picked', 'acknowledged', 'out_for_delivery', 'delivered', 'failed', 'cancelled') DEFAULT 'pending'");

        DB::table('fulfillment_requests')
            ->where('status', 'acknowledged')
            ->update(['status' => 'picked']);

        DB::table('fulfillment_requests')
            ->where('status', 'failed')
            ->update(['status' => 'cancelled']);

        DB::statement("ALTER TABLE fulfillment_requests MODIFY COLUMN status ENUM('pending', 'processing', 'picked', 'out_for_delivery', 'delivered', 'cancelled') DEFAULT 'pending'");
    }
};
