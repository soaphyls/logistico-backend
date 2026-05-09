<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_products', function ($table) {
            $table->boolean('is_approved')->default(false)->after('is_active');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()->after('is_approved');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('rejection_reason')->nullable()->after('approved_at');
            $table->string('warehouse_location')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('partner_products', function ($table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn([
                'is_approved',
                'approved_by',
                'approved_at',
                'rejection_reason',
                'warehouse_location',
            ]);
        });
    }
};
