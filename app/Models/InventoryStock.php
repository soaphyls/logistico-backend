<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStock extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'product_id',
        'product_name',
        'sku',
        'quantity_on_hand',
        'quantity_allocated',
        'bin_location',
        'reorder_level',
        'unit',
        'unit_cost',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function partnerProduct(): BelongsTo
    {
        return $this->belongsTo(PartnerProduct::class, 'product_id');
    }

    public function getQuantityAvailableAttribute(): int
    {
        return max(0, $this->quantity_on_hand - $this->quantity_allocated);
    }

    public function isLowStock(): bool
    {
        return $this->quantity_on_hand <= $this->reorder_level;
    }

    public function allocate(int $quantity): bool
    {
        if ($quantity > $this->quantity_available) {
            return false;
        }
        $this->quantity_allocated += $quantity;
        return $this->save();
    }

    public function deallocate(int $quantity): bool
    {
        $this->quantity_allocated = max(0, $this->quantity_allocated - $quantity);
        return $this->save();
    }
}