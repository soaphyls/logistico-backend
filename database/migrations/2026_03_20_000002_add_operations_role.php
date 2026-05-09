<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Create operations role if it doesn't exist
        if (!Role::where('name', 'operations')->exists()) {
            Role::create([
                'name' => 'operations',
                'display_name' => 'Operations Staff',
                'description' => 'Manages incoming orders from partners, coordinates deliveries, and updates order statuses',
            ]);
        }

        // Also update RoleSeeder to include operations role for future seeders
    }

    public function down(): void
    {
        Role::where('name', 'operations')->delete();
    }
};
