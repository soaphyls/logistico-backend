<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Change the enum column to a standard VARCHAR to support dynamic categories
        DB::statement("ALTER TABLE expenses MODIFY category VARCHAR(255) NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to enum
        DB::statement("ALTER TABLE expenses MODIFY category ENUM('fuel', 'maintenance', 'salary', 'warehouse_rent', 'utilities', 'office', 'other') NOT NULL");
    }
};
