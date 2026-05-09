<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartnerCustomer extends Model
{
    use HasFactory;

    protected $table = 'partner_customers';

    protected $fillable = [
        'customer_id',
        'partner_id',
        'warehouse_id',
        'staff_id',
        'storage_type',
        'storage_rate',
        'notes',
        'created_by',
        'customer_name',
        'customer_phone',
        'customer_email',
        'customer_address',
        'customer_city',
        'customer_state',
        'customer_notes',
    ];

    protected function casts(): array
    {
        return [
            'storage_type' => 'string',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function products(): HasMany
    {
        return $this->hasMany(PartnerProduct::class, 'partner_customer_id');
    }

    public function fulfillmentRequests(): HasMany
    {
        return $this->hasMany(FulfillmentRequest::class, 'partner_customer_id');
    }

    public function activeProducts(): HasMany
    {
        return $this->hasMany(PartnerProduct::class, 'partner_customer_id')->where('is_active', true);
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
                'currency' => 'NGN', // Default for partners
            ]);
        });
    }
}
