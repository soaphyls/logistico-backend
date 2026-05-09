<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\PartnerCustomer;
use App\Models\PartnerProduct;
use App\Models\FulfillmentRequest;
use App\Models\PartnerModule;
use Illuminate\Database\Seeder;

class PartnerSampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating Partner sample data...');

        // Get warehouses
        $warehouse = Warehouse::first();
        if (!$warehouse) {
            $this->command->error('No warehouse found. Please run WarehouseSeeder first.');
            return;
        }

        // Get or create a super admin user
        $admin = User::whereHas('role', function ($q) {
            $q->where('slug', 'super_admin');
        })->first();

        if (!$admin) {
            $admin = User::first();
        }

        // Sample partner businesses (create in customers table first, then link)
        $partnerBusinesses = [
            [
                'name' => 'TechMart Nigeria',
                'email' => 'info@techmart.ng',
                'phone' => '08012345678',
                'address' => '15 Adeola Odeku Street, Victoria Island, Lagos',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'company_name' => 'TechMart Nigeria Ltd',
            ],
            [
                'name' => 'Fashion Forward',
                'email' => 'hello@fashionforward.ng',
                'phone' => '08023456789',
                'address' => '20 Ozumba Mbadiwe Road, Lagos Island',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'company_name' => 'Fashion Forward Ltd',
            ],
            [
                'name' => 'BabyStore Nigeria',
                'email' => 'sales@babystore.ng',
                'phone' => '08034567890',
                'address' => '5 Maryland Road, Ikeja, Lagos',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'company_name' => 'BabyStore Nigeria',
            ],
            [
                'name' => 'Electronics Hub',
                'email' => 'contact@electronicshub.ng',
                'phone' => '08045678901',
                'address' => '10 Computer Village, Ikeja',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'company_name' => 'Electronics Hub Ltd',
            ],
            [
                'name' => 'Organic Foods Co',
                'email' => 'orders@organicfoods.ng',
                'phone' => '08056789012',
                'address' => '25 Maryland Street, Enugu',
                'city' => 'Enugu',
                'state' => 'Enugu',
                'company_name' => 'Organic Foods Company',
            ],
        ];

        $partnerCustomers = [];
        $partnerProducts = [];

        foreach ($partnerBusinesses as $business) {
            // Create customer in main customers table
            $customer = Customer::create([
                'customer_code' => 'CUS-' . strtoupper(uniqid()),
                'name' => $business['name'],
                'email' => $business['email'],
                'phone' => $business['phone'],
                'address' => $business['address'],
                'city' => $business['city'],
                'state' => $business['state'],
                'type' => 'business',
                'company_name' => $business['company_name'],
                'is_active' => true,
                'created_by' => $admin->id,
            ]);

            // Create partner customer linked to main customer
            $partnerCustomer = PartnerCustomer::create([
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouse->id,
                'staff_id' => $admin->id,
                'storage_type' => 'paid',
                'storage_rate' => rand(30, 60),
                'notes' => 'Sample partner customer',
                'created_by' => $admin->id,
            ]);

            $partnerCustomers[] = $partnerCustomer;

            // Create sample products for each customer (3 products each)
            for ($p = 1; $p <= 3; $p++) {
                $product = PartnerProduct::create([
                    'partner_customer_id' => $partnerCustomer->id,
                    'sku' => strtoupper(substr($business['company_name'], 0, 3)) . '-' . rand(1000, 9999),
                    'name' => 'Sample Product ' . $p . ' - ' . $business['company_name'],
                    'quantity' => rand(50, 200),
                    'is_active' => true,
                ]);
                $partnerProducts[] = $product;
            }
        }

        // Create sample fulfillment requests
        $statuses = ['pending', 'processing', 'picked', 'out_for_delivery', 'delivered', 'cancelled'];

        for ($i = 0; $i < 15; $i++) {
            $partnerCustomer = $partnerCustomers[array_rand($partnerCustomers)];
            $product = $partnerProducts[array_rand($partnerProducts)];
            $status = $statuses[array_rand($statuses)];
            
            FulfillmentRequest::create([
                'partner_customer_id' => $partnerCustomer->id,
                'partner_product_id' => $product->id,
                'staff_id' => $admin->id,
                'quantity' => rand(1, 10),
                'delivery_address' => $partnerCustomer->customer->address,
                'delivery_city' => $partnerCustomer->customer->city,
                'delivery_state' => $partnerCustomer->customer->state,
                'delivery_phone' => $partnerCustomer->customer->phone,
                'status' => $status,
                'requested_by' => $partnerCustomer->customer->name,
                'requested_at' => now()->subDays(rand(1, 10)),
                'notes' => 'Sample fulfillment request #' . ($i + 1),
                'completed_at' => $status === 'delivered' ? now()->subDays(rand(0, 2)) : null,
                'cancelled_at' => $status === 'cancelled' ? now()->subDays(rand(0, 3)) : null,
                'cancel_reason' => $status === 'cancelled' ? 'Customer request' : null,
            ]);
        }

        // Enable the partner module
        PartnerModule::updateOrCreate(
            [],
            ['is_enabled' => true]
        );

        $this->command->info('Partner sample data created successfully!');
        $this->command->info('- ' . count($partnerBusinesses) . ' partner customers');
        $this->command->info('- ' . PartnerProduct::count() . ' products');
        $this->command->info('- ' . FulfillmentRequest::count() . ' fulfillment requests');
        $this->command->info('- Module enabled: true');
    }
}
