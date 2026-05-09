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
        Schema::table('pickup_deliveries', function (Blueprint $table) {
            // Location Fields
            $table->string('pickup_address')->nullable()->after('notes');
            $table->string('pickup_city')->nullable()->after('pickup_address');
            $table->string('pickup_state')->nullable()->after('pickup_city');
            $table->string('pickup_phone')->nullable()->after('pickup_state');
            
            $table->string('delivery_address')->nullable()->after('pickup_phone');
            $table->string('delivery_city')->nullable()->after('delivery_address');
            $table->string('delivery_state')->nullable()->after('delivery_city');
            $table->string('delivery_phone')->nullable()->after('delivery_state');
            
            // Timing
            $table->timestamp('estimated_arrival')->nullable()->after('delivery_phone');
            $table->timestamp('actual_start_time')->nullable()->after('estimated_arrival');
            $table->timestamp('actual_completion_time')->nullable()->after('actual_start_time');
            
            // Route Info
            $table->integer('stop_number')->nullable()->after('actual_completion_time');
            $table->decimal('distance_km', 8, 2)->nullable()->after('stop_number');
            
            // Completion Details
            $table->string('recipient_name')->nullable()->after('distance_km');
            $table->string('recipient_signature')->nullable()->after('recipient_name');
            $table->string('proof_photo')->nullable()->after('recipient_signature');
            
            // Failure Details
            $table->integer('attempt_number')->default(1)->after('proof_photo');
            $table->string('failure_reason')->nullable()->after('attempt_number');
            $table->text('failure_notes')->nullable()->after('failure_reason');
            
            // Additional
            $table->text('completion_notes')->nullable()->after('failure_notes');
            $table->boolean('customer_notified')->default(false)->after('completion_notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pickup_deliveries', function (Blueprint $table) {
            $table->dropColumn([
                'pickup_address',
                'pickup_city',
                'pickup_state',
                'pickup_phone',
                'delivery_address',
                'delivery_city',
                'delivery_state',
                'delivery_phone',
                'estimated_arrival',
                'actual_start_time',
                'actual_completion_time',
                'stop_number',
                'distance_km',
                'recipient_name',
                'recipient_signature',
                'proof_photo',
                'attempt_number',
                'failure_reason',
                'failure_notes',
                'completion_notes',
                'customer_notified',
            ]);
        });
    }
};
