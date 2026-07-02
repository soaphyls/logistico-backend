<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'binary_value', 'mime_type', 'type', 'description'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [];
    }

    public static function setBinary(string $key, string $binary, string $mimeType): ?self
    {
        $setting = static::where('key', $key)->first();

        if ($setting) {
            $setting->update([
                'binary_value' => $binary,
                'mime_type' => $mimeType,
            ]);
        } else {
            $setting = static::create([
                'key' => $key,
                'binary_value' => $binary,
                'mime_type' => $mimeType,
                'type' => 'image',
            ]);
        }

        return $setting;
    }

    public static function clearBinary(string $key): void
    {
        $setting = static::where('key', $key)->first();
        if ($setting) {
            $setting->update([
                'binary_value' => null,
                'mime_type' => null,
                'value' => null,
            ]);
        }
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
