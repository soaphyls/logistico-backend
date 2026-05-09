<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickup_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->onDelete('cascade');
            $table->enum('type', ['pickup', 'delivery']);
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->onDelete('set null');
            $table->timestamp('scheduled_date');
            $table->time('time_window_start')->nullable();
            $table->time('time_window_end')->nullable();
            $table->enum('status', ['scheduled', 'assigned', 'in_progress', 'completed', 'failed'])->default('scheduled');
            $table->timestamp('actual_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_deliveries');
    }
};
