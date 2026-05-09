<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickupDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipment_id',
        'type',
        'dispatcher_id',
        'scheduled_date',
        'time_window',
        'time_window_start',
        'time_window_end',
        'status',
        'actual_date',
        'notes',
        'created_by',
        // Location Fields
        'pickup_address',
        'pickup_city',
        'pickup_state',
        'pickup_phone',
        'delivery_address',
        'delivery_city',
        'delivery_state',
        'delivery_phone',
        // Timing
        'estimated_arrival',
        'actual_start_time',
        'actual_completion_time',
        // Route Info
        'stop_number',
        'distance_km',
        // Completion Details
        'recipient_name',
        'recipient_signature',
        'proof_photo',
        // Failure Details
        'attempt_number',
        'failure_reason',
        'failure_notes',
        // Additional
        'completion_notes',
        'customer_notified',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'datetime',
            'time_window_start' => 'datetime',
            'time_window_end' => 'datetime',
            'actual_date' => 'datetime',
            'estimated_arrival' => 'datetime',
            'actual_start_time' => 'datetime',
            'actual_completion_time' => 'datetime',
            'distance_km' => 'decimal:2',
            'customer_notified' => 'boolean',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
