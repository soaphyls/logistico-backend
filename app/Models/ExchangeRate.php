<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_currency',
        'to_currency',
        'rate',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'is_active' => 'boolean',
        ];
    }

    public static function getRate(string $from, string $to)
    {
        if ($from === $to) {
            return 1.000000;
        }

        $rate = self::where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('is_active', true)
            ->first();

        if ($rate) {
            return $rate->rate;
        }

        // Try inverse rate
        $inverseRate = self::where('from_currency', $to)
            ->where('to_currency', $from)
            ->where('is_active', true)
            ->first();

        if ($inverseRate) {
            return 1 / $inverseRate->rate;
        }

        return null;
    }
}
