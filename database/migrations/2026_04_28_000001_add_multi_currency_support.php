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
        // 1. Add base currency to company settings
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('base_currency', 3)->default('NGN')->after('tracking_prefix');
        });

        // 2. Add preferred currency to customers
        Schema::table('customers', function (Blueprint $table) {
            $table->string('preferred_currency', 3)->nullable()->after('type');
        });

        // 3. Add currency and exchange rate to invoices
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('currency', 3)->default('NGN')->after('total_amount');
            $table->decimal('exchange_rate', 15, 6)->default(1.000000)->after('currency');
            $table->decimal('base_total_amount', 15, 2)->nullable()->after('exchange_rate');
        });

        // 4. Add currency and exchange rate to payments
        Schema::table('payments', function (Blueprint $table) {
            $table->string('currency', 3)->default('NGN')->after('amount');
            $table->decimal('exchange_rate', 15, 6)->default(1.000000)->after('currency');
            $table->decimal('base_amount', 15, 2)->nullable()->after('exchange_rate');
        });

        // 5. Add currency and exchange rate to shipments
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('currency', 3)->default('NGN')->after('shipping_cost');
            $table->decimal('exchange_rate', 15, 6)->default(1.000000)->after('currency');
            $table->decimal('base_shipping_cost', 15, 2)->nullable()->after('exchange_rate');
        });

        // 6. Create exchange rates table for manual management
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('from_currency', 3);
            $table->string('to_currency', 3);
            $table->decimal('rate', 15, 6);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->unique(['from_currency', 'to_currency']);
        });

        // Insert initial exchange rate (USD to NGN example)
        // Note: In a real app, this might be handled via a seeder or UI
        \DB::table('exchange_rates')->insert([
            'from_currency' => 'USD',
            'to_currency' => 'NGN',
            'rate' => 1500.000000,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['currency', 'exchange_rate', 'base_shipping_cost']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['currency', 'exchange_rate', 'base_amount']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['currency', 'exchange_rate', 'base_total_amount']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('preferred_currency');
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('base_currency');
        });
    }
};
