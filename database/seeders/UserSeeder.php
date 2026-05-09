<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $superAdminRole = Role::where('name', 'super_admin')->first();
        $managerRole = Role::where('name', 'operations_manager')->first();
        $csRole = Role::where('name', 'customer_service')->first();
        $warehouseRole = Role::where('name', 'warehouse_officer')->first();
        $dispatcherRole = Role::where('name', 'dispatcher')->first();
        $accountantRole = Role::where('name', 'accountant')->first();

        $users = [
            [
                'name' => 'System Admin',
                'email' => 'admin@logistico.com',
                'password' => Hash::make('Logistico@2024'),
                'phone' => '+2348000000001',
                'role_id' => $superAdminRole->id,
                'is_active' => true,
            ],
            [
                'name' => 'Operations Manager',
                'email' => 'manager@logistico.com',
                'password' => Hash::make('Logistico@2024'),
                'phone' => '+2348000000002',
                'role_id' => $managerRole->id,
                'is_active' => true,
            ],
            [
                'name' => 'Customer Service Officer',
                'email' => 'cs@logistico.com',
                'password' => Hash::make('Logistico@2024'),
                'phone' => '+2348000000003',
                'role_id' => $csRole->id,
                'is_active' => true,
            ],
            [
                'name' => 'Warehouse Officer',
                'email' => 'warehouse@logistico.com',
                'password' => Hash::make('Logistico@2024'),
                'phone' => '+2348000000004',
                'role_id' => $warehouseRole->id,
                'is_active' => true,
            ],
            [
                'name' => 'Dispatcher',
                'email' => 'dispatcher@logistico.com',
                'password' => Hash::make('Logistico@2024'),
                'phone' => '+2348000000005',
                'role_id' => $dispatcherRole->id,
                'is_active' => true,
            ],
            [
                'name' => 'Accountant',
                'email' => 'accountant@logistico.com',
                'password' => Hash::make('Logistico@2024'),
                'phone' => '+2348000000006',
                'role_id' => $accountantRole->id,
                'is_active' => true,
            ],
        ];

        foreach ($users as $userData) {
            User::create($userData);
        }

        $this->command->info('Default users seeded successfully!');
        $this->command->info('Login credentials:');
        $this->command->info('  Admin: admin@logistico.com / Logistico@2024');
        $this->command->info('  Manager: manager@logistico.com / Logistico@2024');
        $this->command->info('  Dispatcher: dispatcher@logistico.com / Logistico@2024');
        $this->command->info('  Accountant: accountant@logistico.com / Logistico@2024');
    }
}
