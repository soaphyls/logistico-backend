<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Salary extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'base_salary',
        'allowances',
        'deductions',
        'net_salary',
        'payment_method',
        'payment_reference',
        'payment_date',
        'status',
        'month',
        'year',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:2',
            'allowances' => 'decimal:2',
            'deductions' => 'decimal:2',
            'net_salary' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected static function booted()
    {
        static::creating(function ($salary) {
            if (empty($salary->net_salary)) {
                $salary->net_salary = $salary->base_salary + $salary->allowances - $salary->deductions;
            }
            if (empty($salary->recorded_by)) {
                $salary->recorded_by = auth()->id();
            }
        });

        static::updating(function ($salary) {
            if ($salary->isDirty(['base_salary', 'allowances', 'deductions'])) {
                $salary->net_salary = $salary->base_salary + $salary->allowances - $salary->deductions;
            }
        });
    }
}
