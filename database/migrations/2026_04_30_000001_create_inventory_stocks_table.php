<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->onDelete('cascade');
            $table->foreignId('product_id')->nullable()->constrained('partner_products')->onDelete('set null');
            $table->string('product_name')->nullable();
            $table->string('sku')->nullable();
            $table->integer('quantity_on_hand')->default(0);
            $table->integer('quantity_allocated')->default(0);
            $table->string('bin_location')->nullable();
            $table->integer('reorder_level')->default(10);
            $table->string('unit')->default('pcs');
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'product_id']);
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stocks');
    }
};