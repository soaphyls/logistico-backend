<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shipment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tracking_number',
        'customer_id',
        'sender_name',
        'sender_phone',
        'sender_address',
        'sender_city',
        'sender_state',
        'receiver_name',
        'receiver_phone',
        'receiver_address',
        'receiver_city',
        'receiver_state',
        'email',
        'shipment_type',
        'weight',
        'dimensions',
        'number_of_pieces',
        'package_type',
        'declared_value',
        'shipping_cost',
        'currency',
        'exchange_rate',
        'base_shipping_cost',
        'is_priority',
        'description',
        'status',
        'warehouse_id',
        'shelf_position',
        'dispatcher_id',
        'vehicle_id',
        'assigned_by',
        'scheduled_pickup_date',
        'scheduled_delivery_date',
        'actual_pickup_date',
        'actual_delivery_date',
        'proof_of_delivery',
        'delivery_notes',
        'failure_reason',
        'created_by',
        // Special Handling
        'is_fragile',
        'is_hazardous',
        'is_perishable',
        'is_valuable',
        // Financial
        'cod_amount',
        'payment_status',
        'insurance_cost',
        'discount_amount',
        'discount_reason',
        // Delivery Options
        'delivery_time_slot',
        'signature_required',
        'id_verification_required',
        'notify_sender_on_delivery',
        'notify_receiver_on_pickup',
        'contact_preference',
        // Return Shipment
        'is_return_shipment',
        'original_tracking_number',
        'return_reason',
        // Customer Reference
        'customer_reference',
        // Delivery Attempts
        'delivery_attempts',
        'last_delivery_attempt',
        // Recipient Info
        'recipient_name',
        'recipient_signature',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_pickup_date' => 'datetime',
            'scheduled_delivery_date' => 'datetime',
            'actual_pickup_date' => 'datetime',
            'actual_delivery_date' => 'datetime',
            'last_delivery_attempt' => 'datetime',
            'weight' => 'decimal:2',
            'declared_value' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'base_shipping_cost' => 'decimal:2',
            'insurance_cost' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'cod_amount' => 'decimal:2',
            'is_priority' => 'boolean',
            'is_fragile' => 'boolean',
            'is_hazardous' => 'boolean',
            'is_perishable' => 'boolean',
            'is_valuable' => 'boolean',
            'is_return_shipment' => 'boolean',
            'signature_required' => 'boolean',
            'id_verification_required' => 'boolean',
            'notify_sender_on_delivery' => 'boolean',
            'notify_receiver_on_pickup' => 'boolean',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($shipment) {
            if (empty($shipment->tracking_number)) {
                $shipment->tracking_number = self::generateTrackingNumber();
            }
        });

        static::updated(function ($shipment) {
            // If dispatcher_id was just set or changed
            if ($shipment->isDirty('dispatcher_id') && $shipment->dispatcher_id) {
                try {
                    $botEngine = app(\App\Services\Bot\BotEngine::class);
                    $botEngine->notifyDispatcherAssignment($shipment);
                } catch (\Exception $e) {
                    \Log::error('Bot Notification Error: ' . $e->getMessage());
                }
            }
        });
    }

    public static function generateTrackingNumber(): string
    {
        $prefix = CompanySetting::getTrackingPrefix();
        $date = now()->format('Ymd');
        $lastShipment = self::whereDate('created_at', today())->latest()->first();
        
        // Check if the last shipment uses the same prefix
        if ($lastShipment && str_starts_with($lastShipment->tracking_number, $prefix)) {
            $sequence = $lastShipment ? (int) substr($lastShipment->tracking_number, -4) + 1 : 1;
        } else {
            $sequence = 1;
        }
        
        return $prefix . '-' . $date . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(Dispatcher::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ShipmentStatusHistory::class)->orderBy('created_at', 'desc');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function pickupDeliveries(): HasMany
    {
        return $this->hasMany(PickupDelivery::class);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInTransit($query)
    {
        return $query->whereIn('status', ['in_transit', 'out_for_delivery']);
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', 'delivered');
    }

    public function fulfillmentRequest(): HasOne
    {
        return $this->hasOne(FulfillmentRequest::class);
    }
}
