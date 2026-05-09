<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('amount_paid', 15, 2)->default(0)->after('total_amount');
            $table->decimal('balance_due', 15, 2)->default(0)->after('amount_paid');
            $table->string('payment_link')->nullable()->unique()->after('balance_due');
            $table->timestamp('paid_at')->nullable()->after('payment_link');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['amount_paid', 'balance_due', 'payment_link', 'paid_at']);
        });
    }
};
