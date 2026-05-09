<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'invoice_number',
        'shipment_id',
        'customer_id',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'discount',
        'total_amount',
        'currency',
        'exchange_rate',
        'base_total_amount',
        'amount_paid',
        'balance_due',
        'status',
        'due_date',
        'notes',
        'payment_link',
        'paid_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'base_total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($invoice) {
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = self::generateInvoiceNumber();
            }
        });
    }

    public static function generateInvoiceNumber(): string
    {
        $date = now()->format('Ymd');
        $lastInvoice = self::whereDate('created_at', today())->latest()->first();
        $sequence = $lastInvoice ? (int) substr($lastInvoice->invoice_number, -4) + 1 : 1;
        return 'INV-' . $date . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function fulfillmentRequests(): HasMany
    {
        return $this->hasMany(FulfillmentRequest::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function getAmountPaidAttribute(): float
    {
        return $this->payments()->sum('amount');
    }

    public function getAmountDueAttribute(): float
    {
        return $this->total_amount - $this->amount_paid;
    }

    public static function generatePaymentLink(Invoice $invoice): string
    {
        $token = bin2hex(random_bytes(16));
        $invoice->update([
            'payment_link' => $token,
            'balance_due' => $invoice->total_amount - $invoice->amount_paid,
        ]);
        return url("/pay/{$invoice->id}/{$token}");
    }

    public function getPaymentUrlAttribute(): ?string
    {
        if (!$this->payment_link) {
            return null;
        }
        return url("/pay/{$this->id}/{$this->payment_link}");
    }

    public function isPaymentLinkValid(): bool
    {
        return !empty($this->payment_link);
    }
}
