<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            UserSeeder::class,
            WarehouseSeeder::class,
            VehicleSeeder::class,
            DispatcherSeeder::class,
            CustomerSeeder::class,
            InventorySeeder::class,
            ShipmentSeeder::class,
            InvoiceSeeder::class,
            ExpenseSeeder::class,
            TaskSeeder::class,
        ]);
    }
}
