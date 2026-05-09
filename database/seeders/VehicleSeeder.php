<?php

namespace Database\Seeders;

use App\Models\Vehicle;
use Illuminate\Database\Seeder;

class VehicleSeeder extends Seeder
{
    public function run(): void
    {
        $vehicles = [
            [
                'plate_number' => 'LAG-001',
                'make' => 'Honda',
                'model' => 'Click',
                'year' => 2023,
                'type' => 'bike',
                'status' => 'available',
            ],
            [
                'plate_number' => 'LAG-002',
                'make' => 'Toyota',
                'model' => 'Hiace',
                'year' => 2022,
                'type' => 'van',
                'status' => 'available',
                'next_maintenance_date' => now()->addMonths(2),
            ],
            [
                'plate_number' => 'LAG-003',
                'make' => 'Mercedes',
                'model' => 'Actros',
                'year' => 2021,
                'type' => 'truck',
                'status' => 'available',
                'next_maintenance_date' => now()->addWeek(2),
            ],
            [
                'plate_number' => 'LAG-004',
                'make' => 'Ford',
                'model' => 'Ranger',
                'year' => 2023,
                'type' => 'pickup',
                'status' => 'available',
            ],
        ];

        foreach ($vehicles as $vehicle) {
            Vehicle::create($vehicle);
        }
    }
}
