<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerProduct extends Model
{
    use HasFactory;

    protected $table = 'partner_products';

    protected $fillable = [
        'partner_customer_id',
        'sku',
        'name',
        'description',
        'quantity',
        'weight',
        'dimensions',
        'reorder_level',
        'unit_cost',
        'storage_location',
        'is_active',
        'is_approved',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'warehouse_location',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reorder_level' => 'integer',
            'unit_cost' => 'decimal:2',
            'is_active' => 'boolean',
            'is_approved' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function partnerCustomer(): BelongsTo
    {
        return $this->belongsTo(PartnerCustomer::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isLowStock(): bool
    {
        return $this->quantity <= $this->reorder_level;
    }
}
