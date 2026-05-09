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
            // Package Details
            $table->integer('number_of_pieces')->default(1)->after('dimensions');
            $table->string('package_type')->nullable()->after('number_of_pieces'); // box, envelope, pallet, etc.
            
            // Special Handling
            $table->boolean('is_fragile')->default(false)->after('package_type');
            $table->boolean('is_hazardous')->default(false)->after('is_fragile');
            $table->boolean('is_perishable')->default(false)->after('is_hazardous');
            $table->boolean('is_valuable')->default(false)->after('is_perishable');
            
            // Financial
            $table->decimal('cod_amount', 10, 2)->nullable()->after('is_valuable');
            $table->enum('payment_status', ['paid', 'pending', 'partial', 'refunded'])->default('pending')->after('cod_amount');
            $table->decimal('insurance_cost', 10, 2)->nullable()->after('payment_status');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('insurance_cost');
            $table->string('discount_reason')->nullable()->after('discount_amount');
            
            // Delivery Options
            $table->enum('delivery_time_slot', ['morning', 'afternoon', 'evening', 'anytime'])->default('anytime')->after('discount_reason');
            $table->boolean('signature_required')->default(true)->after('delivery_time_slot');
            $table->boolean('id_verification_required')->default(false)->after('signature_required');
            $table->boolean('notify_sender_on_delivery')->default(true)->after('id_verification_required');
            $table->boolean('notify_receiver_on_pickup')->default(false)->after('notify_sender_on_delivery');
            
            // Contact Preferences
            $table->string('contact_preference')->nullable()->after('notify_receiver_on_pickup'); // call, sms, whatsapp, email
            
            // Return Shipment
            $table->boolean('is_return_shipment')->default(false)->after('contact_preference');
            $table->string('original_tracking_number')->nullable()->after('is_return_shipment');
            $table->string('return_reason')->nullable()->after('original_tracking_number');
            
            // Customer Reference
            $table->string('customer_reference')->nullable()->after('return_reason'); // Client's PO number
            
            // Delivery Attempts
            $table->integer('delivery_attempts')->default(0)->after('customer_reference');
            $table->timestamp('last_delivery_attempt')->nullable()->after('delivery_attempts');
            
            // Recipient Info (captured at delivery)
            $table->string('recipient_name')->nullable()->after('last_delivery_attempt');
            $table->string('recipient_signature')->nullable()->after('recipient_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn([
                'number_of_pieces',
                'package_type',
                'is_fragile',
                'is_hazardous',
                'is_perishable',
                'is_valuable',
                'cod_amount',
                'payment_status',
                'insurance_cost',
                'discount_amount',
                'discount_reason',
                'delivery_time_slot',
                'signature_required',
                'id_verification_required',
                'notify_sender_on_delivery',
                'notify_receiver_on_pickup',
                'contact_preference',
                'is_return_shipment',
                'original_tracking_number',
                'return_reason',
                'customer_reference',
                'delivery_attempts',
                'last_delivery_attempt',
                'recipient_name',
                'recipient_signature',
            ]);
        });
    }
};
