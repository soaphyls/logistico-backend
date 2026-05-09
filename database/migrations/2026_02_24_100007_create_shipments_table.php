<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_number')->unique();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');
            $table->string('sender_name');
            $table->string('sender_phone');
            $table->text('sender_address');
            $table->string('receiver_name');
            $table->string('receiver_phone');
            $table->text('receiver_address');
            $table->string('receiver_city');
            $table->string('receiver_state');
            $table->enum('shipment_type', ['parcel', 'bulk_cargo', 'doorstep', 'interstate']);
            $table->decimal('weight', 8, 2)->nullable();
            $table->string('dimensions')->nullable();
            $table->decimal('declared_value', 10, 2)->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['pending', 'picked_up', 'at_warehouse', 'in_transit', 'out_for_delivery', 'delivered', 'failed'])->default('pending');
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->onDelete('set null');
            $table->string('shelf_position')->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->onDelete('set null');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->onDelete('set null');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('scheduled_pickup_date')->nullable();
            $table->timestamp('scheduled_delivery_date')->nullable();
            $table->timestamp('actual_pickup_date')->nullable();
            $table->timestamp('actual_delivery_date')->nullable();
            $table->string('proof_of_delivery')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
