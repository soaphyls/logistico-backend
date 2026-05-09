<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'description'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [];
    }

    public static function get($key, $default = null)
    {
        $setting = static::where('key', $key)->first();
        
        if (!$setting) {
            return $default;
        }

        // Decrypt if encrypted type
        if ($setting->type === 'encrypted' && $setting->value) {
            return decrypt($setting->value);
        }

        return $setting->value ?? $default;
    }

    public static function set($key, $value, $type = 'text')
    {
        $setting = static::where('key', $key)->first();

        // Encrypt if encrypted type
        if ($type === 'encrypted' && $value) {
            $value = encrypt($value);
        }

        if ($setting) {
            $setting->update(['value' => $value]);
        } else {
            static::create(['key' => $key, 'value' => $value, 'type' => $type]);
        }

        return $setting;
    }

    public static function getPublicSettings()
    {
        $settings = static::all();
        
        return $settings->map(function ($setting) {
            // Mask encrypted values
            if ($setting->type === 'encrypted' && $setting->value) {
                $decrypted = decrypt($setting->value);
                $setting->value = '********' . substr($decrypted, -4);
                $setting->is_masked = true;
            }
            return $setting;
        })->keyBy('key');
    }
}
