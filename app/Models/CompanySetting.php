<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        $settings = self::orderBy('id', 'asc')->first();
        
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
