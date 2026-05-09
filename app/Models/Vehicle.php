<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'plate_number',
        'make',
        'model',
        'year',
        'type',
        'status',
        'last_maintenance_date',
        'next_maintenance_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'last_maintenance_date' => 'date',
            'next_maintenance_date' => 'date',
        ];
    }

    public function dispatcher(): HasOne
    {
        return $this->hasOne(Dispatcher::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeUnderMaintenance($query)
    {
        return $query->where('status', 'maintenance');
    }

    public function scopeDueForMaintenance($query)
    {
        return $query->where(function ($q) {
            $q->where('next_maintenance_date', '<=', now()->addWeek())
              ->orWhere('next_maintenance_date', '<', now());
        })->where('status', '!=', 'inactive');
    }
}
