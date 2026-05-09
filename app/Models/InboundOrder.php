<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_number',
        'partner_customer_id',
        'warehouse_id',
        'partner_product_id',
        'product_name',
        'sku',
        'expected_quantity',
        'received_quantity',
        'status',
        'carrier',
        'vehicle_number',
        'expected_arrival_date',
        'received_at',
        'received_by',
        'notes',
        'discrepancy_notes',
    ];

    protected function casts(): array
    {
        return [
            'expected_arrival_date' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    public function partnerCustomer(): BelongsTo
    {
        return $this->belongsTo(PartnerCustomer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function partnerProduct(): BelongsTo
    {
        return $this->belongsTo(PartnerProduct::class, 'partner_product_id');
    }

    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public static function generateReferenceNumber(): string
    {
        $prefix = 'ASN-' . date('Ymd');
        $lastOrder = static::where('reference_number', 'like', $prefix . '%')
            ->orderBy('id', 'desc')
            ->first();
        
        $sequence = $lastOrder ? (intval(substr($lastOrder->reference_number, -4)) + 1) : 1;
        
        return $prefix . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    public function isFullyReceived(): bool
    {
        return $this->received_quantity >= $this->expected_quantity;
    }

    public function getRemainingQuantityAttribute(): int
    {
        return max(0, $this->expected_quantity - $this->received_quantity);
    }
}