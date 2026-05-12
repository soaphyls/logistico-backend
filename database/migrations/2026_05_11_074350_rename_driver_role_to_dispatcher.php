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
        \DB::table('roles')->where('slug', 'driver')->update([
            'slug' => 'dispatcher',
            'name' => 'Dispatcher',
            'display_name' => 'Dispatcher'
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \DB::table('roles')->where('slug', 'dispatcher')->update([
            'slug' => 'driver',
            'name' => 'Driver',
            'display_name' => 'Driver'
        ]);
    }
};
