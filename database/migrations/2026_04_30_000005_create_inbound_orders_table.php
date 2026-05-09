<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->unique();
            $table->foreignId('partner_customer_id')->constrained('partner_customers')->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained('warehouses')->onDelete('restrict');
            $table->foreignId('partner_product_id')->nullable()->constrained('partner_products')->onDelete('set null');
            $table->string('product_name')->nullable();
            $table->string('sku')->nullable();
            $table->integer('expected_quantity')->default(0);
            $table->integer('received_quantity')->default(0);
            $table->string('status')->default('pending'); // pending, partially_received, received, cancelled
            $table->string('carrier')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->timestamp('expected_arrival_date')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->text('discrepancy_notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('reference_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_orders');
    }
};