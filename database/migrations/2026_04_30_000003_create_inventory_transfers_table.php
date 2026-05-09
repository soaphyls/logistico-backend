<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->unique();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->onDelete('restrict');
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->onDelete('restrict');
            $table->foreignId('product_id')->nullable()->constrained('partner_products')->onDelete('set null');
            $table->string('product_name')->nullable();
            $table->string('sku')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('status')->default('draft'); // draft, pending_approval, approved, in_transit, received, cancelled
            $table->foreignId('requested_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('reference_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
    }
};