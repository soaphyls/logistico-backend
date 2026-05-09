<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dispatcher extends Model
{
    use HasFactory;

    protected $table = 'dispatchers';

    protected $fillable = [
        'user_id',
        'vehicle_id',
        'license_number',
        'license_expiry',
        'total_deliveries',
        'successful_deliveries',
        'is_available',
    ];

    protected function casts(): array
    {
        return [
            'license_expiry' => 'date',
            'is_available' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'dispatcher_id');
    }

    public function pickupDeliveries(): HasMany
    {
        return $this->hasMany(PickupDelivery::class, 'dispatcher_id');
    }

    public function fulfillmentRequests(): HasMany
    {
        return $this->hasMany(FulfillmentRequest::class, 'dispatcher_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function getSuccessRateAttribute(): float
    {
        if ($this->total_deliveries === 0) {
            return 0;
        }
        return round(($this->successful_deliveries / $this->total_deliveries) * 100, 2);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }
}
