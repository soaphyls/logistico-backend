<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_products', function (Blueprint $table) {
            $table->decimal('weight', 10, 2)->nullable()->after('quantity');
            $table->string('dimensions')->nullable()->after('weight');
        });
    }

    public function down(): void
    {
        Schema::table('partner_products', function (Blueprint $table) {
            $table->dropColumn(['weight', 'dimensions']);
        });
    }
};
