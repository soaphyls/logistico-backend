<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->enum('status', ['lead', 'prospect', 'customer'])->default('lead')->after('is_active');
            $table->string('source')->nullable()->after('status');
            $table->integer('lead_score')->default(0)->after('source');
            $table->timestamp('converted_at')->nullable()->after('lead_score');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['status', 'source', 'lead_score', 'converted_at']);
        });
    }
};
