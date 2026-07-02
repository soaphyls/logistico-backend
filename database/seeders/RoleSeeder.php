<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name' => 'super_admin',
                'display_name' => 'Super Admin',
                'description' => 'Full system access - can manage all users and settings',
            ],
            [
                'name' => 'operations_manager',
                'display_name' => 'Operations Manager',
                'description' => 'Manages shipments, dispatchers, warehouses, and day-to-day operations',
            ],
            [
                'name' => 'operations',
                'display_name' => 'Operations Staff',
                'description' => 'Manages incoming orders from partners, coordinates deliveries, and updates order statuses',
            ],
            [
                'name' => 'customer_service',
                'display_name' => 'Customer Service Officer',
                'description' => 'Handles customer inquiries, creates shipments, and manages pickups',
            ],
            [
                'name' => 'warehouse_officer',
                'display_name' => 'Warehouse Officer',
                'description' => 'Manages warehouse inventory and shipment processing',
            ],
            [
                'name' => 'dispatcher',
                'display_name' => 'Dispatcher',
                'description' => 'Dispatches shipments and updates delivery status',
            ],
            [
                'name' => 'accountant',
                'display_name' => 'Accountant',
                'description' => 'Manages invoices, payments, and expenses',
            ],
            [
                'name' => 'partner',
                'display_name' => 'Partner',
                'description' => 'Logistics partner organization account. Has its own staff sub-accounts (parent_id linkage) and uses the Partner Portal.',
            ],
            [
                'name' => 'partner_staff',
                'display_name' => 'Partner Staff',
                'description' => 'Sub-account of a partner organization. Shares data scope with the parent partner via parent_id.',
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['name' => $role['name']], $role);
        }
    }
}
