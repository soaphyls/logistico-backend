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
        // 1. Wallets Table
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner'); // customer or partner
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Wallet Transactions Table
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->onDelete('cascade');
            $table->enum('type', ['credit', 'debit']);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->string('reference')->unique();
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // 3. COD Ledger Table
        Schema::create('cod_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->onDelete('cascade');
            $table->foreignId('dispatcher_id')->constrained('dispatchers')->onDelete('cascade');
            $table->decimal('collected_amount', 15, 2);
            $table->decimal('shipping_fee', 15, 2);
            $table->decimal('merchant_remittance', 15, 2);
            $table->string('currency', 3)->default('NGN');
            $table->enum('status', ['pending', 'collected', 'remitted', 'cancelled'])->default('pending');
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('remitted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cod_ledger');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
