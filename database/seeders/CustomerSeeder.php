<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $adminUser = User::where('email', 'admin@logistico.com')->first();
        
        $customers = [
            ['name' => 'ABC Trading Company', 'email' => 'info@abctrading.com', 'phone' => '+2348012345678', 'address' => '15 Commerce Street, Lagos', 'city' => 'Lagos', 'state' => 'Lagos', 'type' => 'business', 'company_name' => 'ABC Trading Company'],
            ['name' => 'John Ibrahim', 'email' => 'john.ibrahim@email.com', 'phone' => '+2348012345679', 'address' => '20 Adeola Street, Lagos', 'city' => 'Lagos', 'state' => 'Lagos', 'type' => 'individual'],
            ['name' => 'XYZ Enterprises', 'email' => 'contact@xyzenterprises.com', 'phone' => '+2348012345680', 'address' => '45 Industrial Avenue, Abuja', 'city' => 'Abuja', 'state' => 'FCT', 'type' => 'business', 'company_name' => 'XYZ Enterprises'],
            ['name' => 'Mary Johnson', 'email' => 'mary.j@email.com', 'phone' => '+2348012345681', 'address' => '10 Garden Road, Port Harcourt', 'city' => 'Port Harcourt', 'state' => 'Rivers', 'type' => 'individual'],
            ['name' => 'Global Supplies Ltd', 'email' => 'orders@globalsupplies.com', 'phone' => '+2348012345682', 'address' => '30 Trade Center, Lagos', 'city' => 'Lagos', 'state' => 'Lagos', 'type' => 'business', 'company_name' => 'Global Supplies Ltd'],
            ['name' => 'Ahmed Bello', 'email' => 'ahmed.b@email.com', 'phone' => '+2348012345683', 'address' => '25 Mosque Street, Kano', 'city' => 'Kano', 'state' => 'Kano', 'type' => 'individual'],
            ['name' => 'Tech Solutions Inc', 'email' => 'sales@techsolutions.com', 'phone' => '+2348012345684', 'address' => '50 Tech Park, Lagos', 'city' => 'Lagos', 'state' => 'Lagos', 'type' => 'business', 'company_name' => 'Tech Solutions Inc'],
            ['name' => 'Sarah Williams', 'email' => 'sarah.w@email.com', 'phone' => '+2348012345685', 'address' => '8 Lagos Street, Ibadan', 'city' => 'Ibadan', 'state' => 'Oyo', 'type' => 'individual'],
            ['name' => 'Prime Logistics', 'email' => 'info@primelogistics.com', 'phone' => '+2348012345686', 'address' => '35 Warehouse Road, Lagos', 'city' => 'Lagos', 'state' => 'Lagos', 'type' => 'business', 'company_name' => 'Prime Logistics'],
            ['name' => 'David Chen', 'email' => 'david.chen@email.com', 'phone' => '+2348012345687', 'address' => '12 Victoria Island, Lagos', 'city' => 'Lagos', 'state' => 'Lagos', 'type' => 'individual'],
        ];

        foreach ($customers as $index => $customerData) {
            $lastCustomer = Customer::latest()->first();
            $sequence = $lastCustomer ? (int) substr($lastCustomer->customer_code, -4) + 1 : 1;
            
            Customer::create(array_merge($customerData, [
                'customer_code' => 'CUS-' . str_pad($sequence + $index, 4, '0', STR_PAD_LEFT),
                'created_by' => $adminUser->id,
                'is_active' => true,
            ]));
        }
    }
}
