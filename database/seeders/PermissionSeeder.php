<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Create Permissions
        $permissions = [
            // Tasks
            ['name' => 'View Tasks', 'slug' => 'task.view', 'category' => 'tasks', 'description' => 'View all tasks'],
            ['name' => 'Create Tasks', 'slug' => 'task.create', 'category' => 'tasks', 'description' => 'Create new tasks'],
            ['name' => 'Assign Dispatcher to Task', 'slug' => 'task.assign_dispatcher', 'category' => 'tasks', 'description' => 'Assign dispatchers to tasks'],
            ['name' => 'Close Tasks', 'slug' => 'task.close', 'category' => 'tasks', 'description' => 'Close/complete tasks'],
            
            // Dispatchers
            ['name' => 'View Dispatchers', 'slug' => 'dispatcher.view', 'category' => 'dispatchers', 'description' => 'View dispatcher information'],
            ['name' => 'Contact Dispatchers', 'slug' => 'dispatcher.contact', 'category' => 'dispatchers', 'description' => 'View and contact dispatcher details'],
            
            // Reports
            ['name' => 'View Reports', 'slug' => 'reports.view', 'category' => 'reports', 'description' => 'View reports and analytics'],
            ['name' => 'Export Reports', 'slug' => 'reports.export', 'category' => 'reports', 'description' => 'Export reports data'],
            
            // Shipments
            ['name' => 'View Shipments', 'slug' => 'shipment.view', 'category' => 'shipments', 'description' => 'View all shipments'],
            ['name' => 'Create Shipments', 'slug' => 'shipment.create', 'category' => 'shipments', 'description' => 'Create new shipments'],
            ['name' => 'Update Shipments', 'slug' => 'shipment.update', 'category' => 'shipments', 'description' => 'Update shipment details'],
            ['name' => 'Delete Shipments', 'slug' => 'shipment.delete', 'category' => 'shipments', 'description' => 'Delete shipments'],
            
            // Customers
            ['name' => 'View Customers', 'slug' => 'customer.view', 'category' => 'customers', 'description' => 'View customer information'],
            ['name' => 'Create Customers', 'slug' => 'customer.create', 'category' => 'customers', 'description' => 'Create new customers'],
            ['name' => 'Update Customers', 'slug' => 'customer.update', 'category' => 'customers', 'description' => 'Update customer details'],
            
            // Billing
            ['name' => 'View Invoices', 'slug' => 'invoice.view', 'category' => 'billing', 'description' => 'View invoices'],
            ['name' => 'Create Invoices', 'slug' => 'invoice.create', 'category' => 'billing', 'description' => 'Create invoices'],
            ['name' => 'View Payments', 'slug' => 'payment.view', 'category' => 'billing', 'description' => 'View payment records'],
            
            // Users
            ['name' => 'View Users', 'slug' => 'user.view', 'category' => 'users', 'description' => 'View user list'],
            ['name' => 'Manage Users', 'slug' => 'user.manage', 'category' => 'users', 'description' => 'Create, update, delete users'],
            ['name' => 'Manage Permissions', 'slug' => 'permission.manage', 'category' => 'users', 'description' => 'Manage user permissions'],
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['slug' => $perm['slug']], $perm);
        }

        // Create Roles
        $roles = [
            [
                'name' => 'Super Admin',
                'slug' => 'super_admin',
                'display_name' => 'Super Admin',
                'description' => 'Full system access',
            ],
            [
                'name' => 'Operations Manager',
                'slug' => 'operations_manager',
                'display_name' => 'Operations Manager',
                'description' => 'Manage operations, shipments, and dispatchers',
            ],
            [
                'name' => 'Customer Service',
                'slug' => 'customer_service',
                'display_name' => 'Customer Service',
                'description' => 'Handle customer inquiries and create shipments',
            ],
            [
                'name' => 'Accountant',
                'slug' => 'accountant',
                'display_name' => 'Accountant',
                'description' => 'Handle billing and payments',
            ],
            [
                'name' => 'Dispatcher',
                'slug' => 'dispatcher',
                'display_name' => 'Dispatcher',
                'description' => 'Delivery dispatcher role',
            ],
            [
                'name' => 'Warehouse Officer',
                'slug' => 'warehouse_officer',
                'display_name' => 'Warehouse Officer',
                'description' => 'Manage warehouse and inventory',
            ],
        ];

        $rolePermissions = [
            'super_admin' => [
                'task.view', 'task.create', 'task.assign_dispatcher', 'task.close',
                'dispatcher.view', 'dispatcher.contact',
                'reports.view', 'reports.export',
                'shipment.view', 'shipment.create', 'shipment.update', 'shipment.delete',
                'customer.view', 'customer.create', 'customer.update',
                'invoice.view', 'invoice.create',
                'payment.view',
                'user.view', 'user.manage', 'permission.manage',
            ],
            'operations_manager' => [
                'task.view', 'task.create', 'task.assign_dispatcher', 'task.close',
                'dispatcher.view', 'dispatcher.contact',
                'reports.view', 'reports.export',
                'shipment.view', 'shipment.create', 'shipment.update',
                'customer.view',
            ],
            'customer_service' => [
                'task.view', 'task.create',
                'dispatcher.view',
                'shipment.view', 'shipment.create',
                'customer.view', 'customer.create',
                'invoice.view',
            ],
            'accountant' => [
                'reports.view',
                'customer.view',
                'invoice.view', 'invoice.create',
                'payment.view',
            ],
            'dispatcher' => [
                'task.view',
                'dispatcher.contact',
                'shipment.view',
            ],
            'warehouse_officer' => [
                'task.view',
                'shipment.view', 'shipment.update',
                'customer.view',
            ],
        ];

        foreach ($roles as $roleData) {
            $role = Role::firstOrCreate(['slug' => $roleData['slug']], $roleData);
            
            // Assign permissions to role
            if (isset($rolePermissions[$roleData['slug']])) {
                $permissionIds = Permission::whereIn('slug', $rolePermissions[$roleData['slug']])
                    ->pluck('id');
                
                $role->permissions()->sync($permissionIds);
            }
        }
    }
}
