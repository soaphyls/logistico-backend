<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Create partner role if it doesn't exist
        if (!Role::where('name', 'partner')->exists()) {
            Role::create([
                'name' => 'partner',
                'display_name' => 'Partner',
                'description' => 'E-commerce partner/vendor with access to partner portal for orders and inventory',
            ]);
        }
    }

    public function down(): void
    {
        Role::where('name', 'partner')->delete();
    }
};
