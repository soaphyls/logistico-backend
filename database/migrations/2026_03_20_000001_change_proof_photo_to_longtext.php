<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pickup_deliveries', function (Blueprint $table) {
            $table->longText('proof_photo')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pickup_deliveries', function (Blueprint $table) {
            $table->string('proof_photo')->nullable()->change();
        });
    }
};
