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
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('sender_city')->nullable()->after('sender_address');
            $table->string('sender_state')->nullable()->after('sender_city');
            $table->decimal('shipping_cost', 10, 2)->nullable()->after('declared_value');
            $table->boolean('is_priority')->default(false)->after('shipping_cost');
            $table->string('receiver_email')->nullable()->after('receiver_state');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['sender_city', 'sender_state', 'shipping_cost', 'is_priority', 'receiver_email']);
        });
    }
};
