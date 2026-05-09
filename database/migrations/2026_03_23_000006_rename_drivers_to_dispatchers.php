<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('drivers', 'dispatchers');

        // Rename driver_id column in fulfillment_requests
        Schema::table('fulfillment_requests', function ($table) {
            $table->renameColumn('driver_id', 'dispatcher_id');
        });

        // Rename driver_id column in pickup_deliveries if it exists
        if (Schema::hasColumn('pickup_deliveries', 'driver_id')) {
            Schema::table('pickup_deliveries', function ($table) {
                $table->renameColumn('driver_id', 'dispatcher_id');
            });
        }

        // Rename driver_id column in shipments if it exists
        if (Schema::hasColumn('shipments', 'driver_id')) {
            Schema::table('shipments', function ($table) {
                $table->renameColumn('driver_id', 'dispatcher_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shipments', 'dispatcher_id')) {
            Schema::table('shipments', function ($table) {
                $table->renameColumn('dispatcher_id', 'driver_id');
            });
        }

        if (Schema::hasColumn('pickup_deliveries', 'dispatcher_id')) {
            Schema::table('pickup_deliveries', function ($table) {
                $table->renameColumn('dispatcher_id', 'driver_id');
            });
        }

        Schema::table('fulfillment_requests', function ($table) {
            $table->renameColumn('dispatcher_id', 'driver_id');
        });

        Schema::rename('dispatchers', 'drivers');
    }
};
