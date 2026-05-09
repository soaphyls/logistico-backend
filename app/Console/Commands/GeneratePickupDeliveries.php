<?php

namespace App\Console\Commands;

use App\Models\PickupDelivery;
use App\Models\Shipment;
use Illuminate\Console\Command;

class GeneratePickupDeliveries extends Command
{
    protected $signature = 'app:generate-pickup-deliveries';
    protected $description = 'Generate pickup/delivery records for existing shipments';

    public function handle()
    {
        $shipments = Shipment::doesntHave('pickupDeliveries')->get();
        
        if ($shipments->isEmpty()) {
            $this->info('All shipments already have pickup/delivery records!');
            return 0;
        }

        $this->info('Found ' . $shipments->count() . ' shipments needing pickup/delivery records...');

        foreach ($shipments as $shipment) {
            // Create pickup record
            PickupDelivery::create([
                'shipment_id' => $shipment->id,
                'type' => 'pickup',
                'scheduled_date' => $shipment->scheduled_pickup_date ?? now()->addDay(),
                'status' => $shipment->status === 'picked_up' ? 'completed' : 'scheduled',
                'actual_date' => $shipment->actual_pickup_date,
                'created_by' => $shipment->created_by,
                'pickup_address' => $shipment->sender_address,
                'pickup_city' => $shipment->sender_city,
                'pickup_state' => $shipment->sender_state,
                'pickup_phone' => $shipment->sender_phone,
                'delivery_address' => $shipment->receiver_address,
                'delivery_city' => $shipment->receiver_city,
                'delivery_state' => $shipment->receiver_state,
                'delivery_phone' => $shipment->receiver_phone,
            ]);

            // Create delivery record
            PickupDelivery::create([
                'shipment_id' => $shipment->id,
                'type' => 'delivery',
                'scheduled_date' => $shipment->scheduled_delivery_date ?? now()->addDays(2),
                'status' => in_array($shipment->status, ['delivered', 'failed']) ? 'completed' : 'scheduled',
                'actual_date' => $shipment->actual_delivery_date,
                'created_by' => $shipment->created_by,
                'pickup_address' => $shipment->sender_address,
                'pickup_city' => $shipment->sender_city,
                'pickup_state' => $shipment->sender_state,
                'pickup_phone' => $shipment->sender_phone,
                'delivery_address' => $shipment->receiver_address,
                'delivery_city' => $shipment->receiver_city,
                'delivery_state' => $shipment->receiver_state,
                'delivery_phone' => $shipment->receiver_phone,
            ]);

            $this->line('Created pickup/delivery for: ' . $shipment->tracking_number);
        }

        $this->info('Done! Created ' . ($shipments->count() * 2) . ' pickup/delivery records.');
        return 0;
    }
}
