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
        Schema::table('partner_products', function (Blueprint $table) {
            $table->integer('defective_quantity')->default(0)->after('quantity');
            $table->text('defective_comment')->nullable()->after('defective_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partner_products', function (Blueprint $table) {
            $table->dropColumn(['defective_quantity', 'defective_comment']);
        });
    }
};
