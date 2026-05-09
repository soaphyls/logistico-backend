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
        Schema::table('fulfillment_requests', function (Blueprint $table) {
            $table->decimal('cod_amount', 15, 2)->nullable()->after('delivery_cost');
            $table->decimal('amount_collected', 15, 2)->nullable()->after('cod_amount');
            $table->decimal('remittance_amount', 15, 2)->nullable()->after('amount_collected');
            $table->string('remittance_status')->default('pending')->after('remittance_amount');
            $table->timestamp('remitted_at')->nullable()->after('remittance_status');
            $table->text('dispute_note')->nullable()->after('remitted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fulfillment_requests', function (Blueprint $table) {
            $table->dropColumn([
                'cod_amount',
                'amount_collected',
                'remittance_amount',
                'remittance_status',
                'remitted_at',
                'dispute_note'
            ]);
        });
    }
};
