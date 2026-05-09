<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FulfillmentActivityLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'fulfillment_request_id',
        'user_id',
        'action',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function fulfillmentRequest(): BelongsTo
    {
        return $this->belongsTo(FulfillmentRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
