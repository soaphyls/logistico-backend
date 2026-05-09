<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FulfillmentRequest extends Model
{
    use HasFactory;

    protected $appends = ['request_number', 'total_amount', 'request_type'];

    protected $fillable = [
        'partner_customer_id',
        'partner_product_id',
        'staff_id',
        'quantity',
        'delivery_address',
        'delivery_city',
        'delivery_state',
        'delivery_phone',
        'delivery_notes',
        'status',
        'delivery_cost',
        'picked_by',
        'dispatcher_id',
        'pickup_delivery_id',
        'shipment_id',
        'invoice_id',
        'notes',
        'requested_by',
        'requested_at',
        'completed_at',
        'cancelled_at',
        'cancel_reason',
        'cancelled_by',
        'failed_at',
        'fail_reason',
        'failed_by',
        'partner_response',
        'new_delivery_address',
        'new_delivery_phone',
        'delay_reason',
        'new_delivery_date',
        'cod_amount',
        'amount_collected',
        'remittance_amount',
        'remittance_status',
        'remitted_at',
        'dispute_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'string',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function getRequestNumberAttribute(): string
    {
        return 'REQ-' . str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    public function getTotalAmountAttribute(): float
    {
        return $this->invoice?->total_amount ?? 0;
    }

    public function getRequestTypeAttribute(): string
    {
        return 'delivery';
    }

    public function partnerCustomer(): BelongsTo
    {
        return $this->belongsTo(PartnerCustomer::class);
    }

    public function partnerProduct(): BelongsTo
    {
        return $this->belongsTo(PartnerProduct::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function picker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'picked_by');
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(Dispatcher::class);
    }

    public function pickupDelivery(): BelongsTo
    {
        return $this->belongsTo(PickupDelivery::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_AWAITING_PARTNER_ACTION = 'awaiting_partner_action';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_AWAITING_RESCHEDULE = 'awaiting_reschedule';

    public function activities(): HasMany
    {
        return $this->hasMany(FulfillmentActivityLog::class, 'fulfillment_request_id');
    }
}
