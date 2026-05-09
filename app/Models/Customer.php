<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_code',
        'name',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'type',
        'preferred_currency',
        'company_name',
        'is_active',
        'status',
        'source',
        'lead_score',
        'converted_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'converted_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function wallet()
    {
        return $this->morphOne(Wallet::class, 'owner');
    }

    protected static function boot()
    {
        parent::boot();

        static::created(function ($customer) {
            $customer->wallet()->create([
                'balance' => 0,
                'currency' => $customer->preferred_currency ?? 'NGN',
            ]);
        });
    }

    public function getOutstandingBalanceAttribute(): float
    {
        return $this->invoices()
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->get()
            ->sum(function ($invoice) {
                return $invoice->total_amount - $invoice->amount_paid;
            });
    }
}
