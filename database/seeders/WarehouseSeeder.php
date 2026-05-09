<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $warehouses = [
            [
                'name' => 'Main Distribution Center',
                'code' => 'WH-001',
                'address' => '123 Logistics Avenue, Industrial Zone',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'capacity' => 1000,
                'is_active' => true,
            ],
            [
                'name' => 'Abuja Storage Hub',
                'code' => 'WH-002',
                'address' => '456 Cargo Road, Airport Area',
                'city' => 'Abuja',
                'state' => 'FCT',
                'capacity' => 500,
                'is_active' => true,
            ],
        ];

        foreach ($warehouses as $warehouse) {
            Warehouse::create($warehouse);
        }
    }
}
