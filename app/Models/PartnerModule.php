<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PartnerModule extends Model
{
    use HasFactory;

    protected $table = 'partner_modules';

    protected $fillable = [
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public static function isEnabled(): bool
    {
        $module = self::first();
        return $module && $module->is_enabled;
    }
}
