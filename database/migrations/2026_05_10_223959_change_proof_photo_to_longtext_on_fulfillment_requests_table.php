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
        Schema::table('fulfillment_requests', function (Blueprint $table) {
            $table->longText('proof_photo')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fulfillment_requests', function (Blueprint $table) {
            $table->string('proof_photo', 255)->nullable()->change();
        });
    }
};
