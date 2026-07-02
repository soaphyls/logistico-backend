<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'label',
        'route',
        'parent_key',
        'icon_name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MenuItem $item) {
            if (is_null($item->sort_order)) {
                $max = (int) static::max('sort_order');
                $item->sort_order = $max + 1;
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_key', 'key');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_key', 'key');
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_key');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }
}
