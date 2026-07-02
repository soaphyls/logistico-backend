<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class CompanySetting extends Model
{
    protected $fillable = [
        'company_name',
        'tracking_prefix',
        'country',
        'state',
        'city',
        'address',
        'phone',
        'email',
        'logo',
        'website',
        'bank_name',
        'account_name',
        'account_number',
        'description',
        'base_currency',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public static function getSettings()
    {
        $count = self::count();

        if ($count > 1) {
            // Multiple rows detected — the most recently updated row is the
            // source of truth, otherwise older seed/test rows can shadow
            // admin edits. Surface a warning so it can be cleaned up.
            Log::warning("company_settings has {$count} rows; using latest updated_at row.");
        }

        $settings = self::orderByDesc('updated_at')->orderByDesc('id')->first();

        if (!$settings) {
            $settings = self::create([
                'company_name' => 'Logistico',
                'tracking_prefix' => 'LOG',
                'base_currency' => 'NGN',
                'is_active' => true,
            ]);
        }

        if (!$settings->is_active) {
            $settings->is_active = true;
            $settings->save();
        }

        return $settings;
    }

    public static function getTrackingPrefix(): string
    {
        $settings = self::getSettings();
        return $settings->tracking_prefix ?? 'LOG';
    }
}
