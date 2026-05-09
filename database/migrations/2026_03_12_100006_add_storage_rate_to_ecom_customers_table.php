<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecom_customers', function (Blueprint $table) {
            $table->decimal('storage_rate', 10, 2)->default(0)->after('storage_type');
        });
    }

    public function down(): void
    {
        Schema::table('ecom_customers', function (Blueprint $table) {
            $table->dropColumn('storage_rate');
        });
    }
};
