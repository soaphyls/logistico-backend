<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_number',
        'source_warehouse_id',
        'destination_warehouse_id',
        'product_id',
        'product_name',
        'sku',
        'quantity',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'shipped_at',
        'received_at',
        'notes',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function partnerProduct(): BelongsTo
    {
        return $this->belongsTo(PartnerProduct::class, 'product_id');
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public static function generateReferenceNumber(): string
    {
        $prefix = 'TRF-' . date('Ymd');
        $lastTransfer = static::where('reference_number', 'like', $prefix . '%')
            ->orderBy('id', 'desc')
            ->first();
        
        $sequence = $lastTransfer ? (intval(substr($lastTransfer->reference_number, -4)) + 1) : 1;
        
        return $prefix . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    public function canApprove(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL]);
    }

    public function canShip(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function canReceive(): bool
    {
        return $this->status === self::STATUS_IN_TRANSIT;
    }
}