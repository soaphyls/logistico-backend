<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CycleCountLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'cycle_count_id',
        'inventory_stock_id',
        'system_quantity',
        'counted_quantity',
        'variance',
        'variance_reason',
        'is_adjusted',
    ];

    public function cycleCount(): BelongsTo
    {
        return $this->belongsTo(CycleCount::class, 'cycle_count_id');
    }

    public function inventoryStock(): BelongsTo
    {
        return $this->belongsTo(InventoryStock::class);
    }

    public static function calculateVariance(int $systemQty, int $countedQty): int
    {
        return $countedQty - $systemQty;
    }
}