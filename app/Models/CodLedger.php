<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodLedger extends Model
{
    use HasFactory;

    protected $table = 'cod_ledger';

    protected $fillable = [
        'shipment_id',
        'dispatcher_id',
        'collected_amount',
        'shipping_fee',
        'merchant_remittance',
        'currency',
        'status',
        'collected_at',
        'remitted_at',
    ];

    protected function casts(): array
    {
        return [
            'collected_amount' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'merchant_remittance' => 'decimal:2',
            'collected_at' => 'datetime',
            'remitted_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(Dispatcher::class);
    }
}
