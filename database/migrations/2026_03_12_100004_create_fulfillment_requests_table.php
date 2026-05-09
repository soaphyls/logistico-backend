<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ecom_customer_id')->constrained('ecom_customers')->onDelete('cascade');
            $table->foreignId('ecom_product_id')->constrained('ecom_products')->onDelete('cascade');
            $table->foreignId('staff_id')->constrained('users')->onDelete('cascade');
            $table->integer('quantity');
            $table->text('delivery_address');
            $table->string('delivery_city');
            $table->string('delivery_state');
            $table->string('delivery_phone');
            $table->text('delivery_notes')->nullable();
            $table->enum('status', ['pending', 'processing', 'picked', 'out_for_delivery', 'delivered', 'cancelled'])->default('pending');
            $table->foreignId('picked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('pickup_delivery_id')->nullable()->constrained('pickup_deliveries')->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('requested_by');
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->string('cancelled_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_requests');
    }
};
