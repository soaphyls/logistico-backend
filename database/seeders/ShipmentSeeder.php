<?php

namespace Database\Seeders;

use App\Models\Shipment;
use App\Models\ShipmentStatusHistory;
use App\Models\User;
use Illuminate\Database\Seeder;

class ShipmentSeeder extends Seeder
{
    public function run(): void
    {
        $adminUser = User::where('email', 'admin@logistico.com')->first();
        
        $shipments = [
            ['customer_id' => 1, 'sender_name' => 'ABC Trading', 'sender_phone' => '+2348012345678', 'sender_address' => '15 Commerce St, Lagos', 'receiver_name' => 'Mike Johnson', 'receiver_phone' => '+2348099999999', 'receiver_address' => '20 Oak Ave, Abuja', 'receiver_city' => 'Abuja', 'receiver_state' => 'FCT', 'shipment_type' => 'parcel', 'weight' => 2.5, 'status' => 'delivered', 'tracking_number' => 'LOG-20260220-0001', 'created_at' => now()->subDays(2)],
            ['customer_id' => 2, 'sender_name' => 'John Ibrahim', 'sender_phone' => '+2348012345679', 'sender_address' => '20 Adeola St, Lagos', 'receiver_name' => 'Gift Okon', 'receiver_phone' => '+2348099999998', 'receiver_address' => '25 River Rd, Port Harcourt', 'receiver_city' => 'Port Harcourt', 'receiver_state' => 'Rivers', 'shipment_type' => 'bulk_cargo', 'weight' => 15.0, 'status' => 'in_transit', 'tracking_number' => 'LOG-20260221-0001', 'created_at' => now()->subDays(1)],
            ['customer_id' => 3, 'sender_name' => 'XYZ Enterprises', 'sender_phone' => '+2348012345680', 'sender_address' => '45 Industrial Ave, Abuja', 'receiver_name' => 'Grace Adeyemi', 'receiver_phone' => '+2348099999997', 'receiver_address' => '30 Church St, Lagos', 'receiver_city' => 'Lagos', 'receiver_state' => 'Lagos', 'shipment_type' => 'doorstep', 'weight' => 1.2, 'status' => 'pending', 'tracking_number' => 'LOG-20260222-0001', 'created_at' => now()->subHours(5)],
            ['customer_id' => 4, 'sender_name' => 'Mary Johnson', 'sender_phone' => '+2348012345681', 'sender_address' => '10 Garden Rd, PH', 'receiver_name' => 'Tom Ik', 'receiver_phone' => '+2348099999996', 'receiver_address' => '55 Market St, Benin', 'receiver_city' => 'Benin', 'receiver_state' => 'Edo', 'shipment_type' => 'interstate', 'weight' => 8.5, 'status' => 'at_warehouse', 'driver_id' => 1, 'warehouse_id' => 1, 'tracking_number' => 'LOG-20260219-0001', 'created_at' => now()->subDays(3)],
            ['customer_id' => 5, 'sender_name' => 'Global Supplies', 'sender_phone' => '+2348012345682', 'sender_address' => '30 Trade Center, Lagos', 'receiver_name' => 'Sara Yusuf', 'receiver_phone' => '+2348099999995', 'receiver_address' => '40 Mosque Rd, Kano', 'receiver_city' => 'Kano', 'receiver_state' => 'Kano', 'shipment_type' => 'parcel', 'weight' => 3.0, 'status' => 'out_for_delivery', 'driver_id' => 2, 'vehicle_id' => 2, 'tracking_number' => 'LOG-20260218-0001', 'created_at' => now()->subDays(4)],
            ['customer_id' => 1, 'sender_name' => 'ABC Trading', 'sender_phone' => '+2348012345678', 'sender_address' => '15 Commerce St, Lagos', 'receiver_name' => 'Paul Ngele', 'receiver_phone' => '+2348099999994', 'receiver_address' => '12 Hospital Rd, Enugu', 'receiver_city' => 'Enugu', 'receiver_state' => 'Enugu', 'shipment_type' => 'bulk_cargo', 'weight' => 25.0, 'status' => 'failed', 'failure_reason' => 'Recipient not available', 'tracking_number' => 'LOG-20260217-0001', 'created_at' => now()->subDays(5)],
            ['customer_id' => 6, 'sender_name' => 'Ahmed Bello', 'sender_phone' => '+2348012345683', 'sender_address' => '25 Mosque St, Kano', 'receiver_name' => 'Joy Adamu', 'receiver_phone' => '+2348099999993', 'receiver_address' => '18 School Lane, Jos', 'receiver_city' => 'Jos', 'receiver_state' => 'Plateau', 'shipment_type' => 'parcel', 'weight' => 1.5, 'status' => 'picked_up', 'tracking_number' => 'LOG-20260223-0001', 'created_at' => now()->subHours(3)],
            ['customer_id' => 7, 'sender_name' => 'Tech Solutions', 'sender_phone' => '+2348012345684', 'sender_address' => '50 Tech Park, Lagos', 'receiver_name' => 'Emma Stone', 'receiver_phone' => '+2348099999992', 'receiver_address' => '22 Beach Rd, Lagos', 'receiver_city' => 'Lagos', 'receiver_state' => 'Lagos', 'shipment_type' => 'doorstep', 'weight' => 0.5, 'status' => 'delivered', 'tracking_number' => 'LOG-20260215-0001', 'created_at' => now()->subDays(7)],
            ['customer_id' => 8, 'sender_name' => 'Sarah Williams', 'sender_phone' => '+2348012345685', 'sender_address' => '8 Lagos St, Ibadan', 'receiver_name' => 'Victor Okafor', 'receiver_phone' => '+2348099999991', 'receiver_address' => '35 Main St, Owerri', 'receiver_city' => 'Owerri', 'receiver_state' => 'Imo', 'shipment_type' => 'interstate', 'weight' => 5.0, 'status' => 'in_transit', 'tracking_number' => 'LOG-20260221-0002', 'created_at' => now()->subDays(1)],
            ['customer_id' => 9, 'sender_name' => 'Prime Logistics', 'sender_phone' => '+2348012345686', 'sender_address' => '35 Warehouse Rd, Lagos', 'receiver_name' => 'Ngozi Eze', 'receiver_phone' => '+2348099999990', 'receiver_address' => '10 Station Rd, Awka', 'receiver_city' => 'Awka', 'receiver_state' => 'Anambra', 'shipment_type' => 'bulk_cargo', 'weight' => 30.0, 'status' => 'pending', 'tracking_number' => 'LOG-20260224-0001', 'created_at' => now()->subHours(2)],
        ];

        foreach ($shipments as $shipmentData) {
            $createdAt = $shipmentData['created_at'] ?? now();
            unset($shipmentData['created_at']);
            
            $shipmentData['created_by'] = $adminUser->id;
            $shipment = Shipment::create($shipmentData);
            $shipment->created_at = $createdAt;
            $shipment->save();

            ShipmentStatusHistory::create([
                'shipment_id' => $shipment->id,
                'status' => $shipment->status,
                'notes' => 'Shipment created',
                'recorded_by' => $adminUser->id,
            ]);
        }
    }
}
