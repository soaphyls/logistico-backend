<?php

namespace Database\Seeders;

use App\Models\Dispatcher;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DispatcherSeeder extends Seeder
{
    public function run(): void
    {
        $dispatcherRole = Role::where('name', 'dispatcher')->first();
        
        $dispatcherUsers = [
            [
                'name' => 'John Doe',
                'email' => 'john.doe@logistico.com',
                'password' => Hash::make('dispatcher123'),
                'phone' => '+2348011111111',
                'role_id' => $dispatcherRole->id,
                'is_active' => true,
            ],
            [
                'name' => 'Jane Smith',
                'email' => 'jane.smith@logistico.com',
                'password' => Hash::make('dispatcher123'),
                'phone' => '+2348012222222',
                'role_id' => $dispatcherRole->id,
                'is_active' => true,
            ],
        ];

        $dispatchers = [
            [
                'license_number' => 'DL-123456',
                'license_expiry' => now()->addYears(2),
                'vehicle_id' => 1,
                'is_available' => true,
            ],
            [
                'license_number' => 'DL-789012',
                'license_expiry' => now()->addYears(1),
                'vehicle_id' => 2,
                'is_available' => true,
            ],
        ];

        foreach ($dispatcherUsers as $index => $userData) {
            $user = User::create($userData);
            
            $dispatcherData = $dispatchers[$index];
            $dispatcherData['user_id'] = $user->id;
            Dispatcher::create($dispatcherData);
        }
    }
}
