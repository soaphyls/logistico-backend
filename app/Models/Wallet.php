<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Wallet extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'owner_id',
        'owner_type',
        'balance',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function deposit($amount, $description = null, $reference = null, $metadata = null)
    {
        $this->balance += $amount;
        $this->save();

        return $this->transactions()->create([
            'type' => 'credit',
            'amount' => $amount,
            'currency' => $this->currency,
            'reference' => $reference ?? 'DEP-' . time() . '-' . rand(1000, 9999),
            'description' => $description ?? 'Wallet Deposit',
            'metadata' => $metadata,
        ]);
    }

    public function withdraw($amount, $description = null, $reference = null, $metadata = null)
    {
        if ($this->balance < $amount) {
            throw new \Exception('Insufficient wallet balance');
        }

        $this->balance -= $amount;
        $this->save();

        return $this->transactions()->create([
            'type' => 'debit',
            'amount' => $amount,
            'currency' => $this->currency,
            'reference' => $reference ?? 'WTH-' . time() . '-' . rand(1000, 9999),
            'description' => $description ?? 'Wallet Withdrawal',
            'metadata' => $metadata,
        ]);
    }
}
