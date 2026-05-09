<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fulfillment_requests', function ($table) {
            $table->timestamp('failed_at')->nullable()->after('cancelled_by');
            $table->string('fail_reason')->nullable()->after('failed_at');
            $table->string('failed_by')->nullable()->after('fail_reason');
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_requests', function ($table) {
            $table->dropColumn(['failed_at', 'fail_reason', 'failed_by']);
        });
    }
};
