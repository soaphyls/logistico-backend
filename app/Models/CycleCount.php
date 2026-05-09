<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CycleCount extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_number',
        'warehouse_id',
        'status',
        'assigned_to',
        'assigned_at',
        'started_at',
        'completed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ADJUSTED = 'adjusted';

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function assignedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CycleCountLine::class, 'cycle_count_id');
    }

    public static function generateReferenceNumber(): string
    {
        $prefix = 'CC-' . date('Ymd');
        $lastCount = static::where('reference_number', 'like', $prefix . '%')
            ->orderBy('id', 'desc')
            ->first();
        
        $sequence = $lastCount ? (intval(substr($lastCount->reference_number, -4)) + 1) : 1;
        
        return $prefix . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    public function getTotalVarianceAttribute(): int
    {
        return $this->lines()->sum('variance');
    }

    public function getVarianceCountAttribute(): int
    {
        return $this->lines()->where('variance', '!=', 0)->count();
    }
}