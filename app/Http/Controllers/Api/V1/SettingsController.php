<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SettingsController extends Controller
{
    /**
     * Public settings - no authentication required
     * Only returns app name, logo, favicon (no secrets)
     */
    public function publicIndex()
    {
        $settings = Setting::whereIn('key', ['app_name', 'app_logo', 'app_favicon', 'primary_color'])->get()->keyBy('key');
        
        return response()->json([
            'settings' => [
                'app_name' => [
                    'value' => $settings['app_name']?->value ?? 'LOGISTICO',
                    'has_value' => !empty($settings['app_name']?->value),
                ],
                'app_logo' => [
                    'value' => $settings['app_logo']?->value ?? null,
                    'has_value' => !empty($settings['app_logo']?->value),
                ],
                'app_favicon' => [
                    'value' => $settings['app_favicon']?->value ?? null,
                    'has_value' => !empty($settings['app_favicon']?->value),
                ],
                'primary_color' => [
                    'value' => $settings['primary_color']?->value ?? '#f97316',
                    'has_value' => !empty($settings['primary_color']?->value),
                ],
            ],
        ]);
    }

    public function index()
    {
        $settings = Setting::all()->keyBy('key');
        
        // Process settings for frontend - mask secrets
        $processed = [];
        foreach ($settings as $key => $setting) {
            $value = $setting->value;
            
            // Mask encrypted values
            if ($setting->type === 'encrypted' && $value) {
                try {
                    $decrypted = decrypt($value);
                    $value = '********' . substr($decrypted, -4);
                    $setting->is_masked = true;
                } catch (\Exception $e) {
                    $value = null;
                }
            }
            
            $processed[$key] = [
                'id' => $setting->id,
                'value' => $value,
                'type' => $setting->type,
                'is_masked' => $setting->is_masked ?? false,
                'has_value' => !empty($setting->value),
            ];
        }
        
        return response()->json([
            'settings' => $processed,
        ]);
    }

    public function updateGeneral(Request $request)
    {
        // Allow any image type for flexibility
        $validator = Validator::make($request->all(), [
            'app_name' => 'nullable|string|max:255',
            'primary_color' => 'nullable|string|max:20',
            'app_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'app_favicon' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,ico|max:512',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Update app name
        if ($request->filled('app_name')) {
            Setting::set('app_name', $request->app_name, 'text');
        }

        // Update primary color
        if ($request->filled('primary_color')) {
            Setting::set('primary_color', $request->primary_color, 'text');
        }

        // Handle logo upload
        $logoFile = $request->file('app_logo');
        if ($logoFile && $logoFile->isValid()) {
            $filename = 'logo.' . $logoFile->getClientOriginalExtension();
            $path = $logoFile->storeAs('settings', $filename, 'public');
            Setting::set('app_logo', $path, 'image');
        }

        // Handle favicon upload
        $faviconFile = $request->file('app_favicon');
        if ($faviconFile && $faviconFile->isValid()) {
            $filename = 'favicon.' . $faviconFile->getClientOriginalExtension();
            $path = $faviconFile->storeAs('settings', $filename, 'public');
            Setting::set('app_favicon', $path, 'image');
        }

        return response()->json([
            'message' => 'General settings updated successfully',
            'settings' => $this->getSettingsArray(),
        ]);
    }

    public function updatePayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_public_key' => 'nullable|string',
            'payment_secret_key' => 'nullable|string',
            'payment_webhook_secret' => 'nullable|string',
            'payment_mode' => 'nullable|in:test,live',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Update payment settings (always encrypt secrets)
        if ($request->has('payment_public_key') && $request->payment_public_key) {
            // Only update if not masked
            if (!$this->isMasked($request->payment_public_key)) {
                Setting::set('payment_public_key', $request->payment_public_key, 'encrypted');
            }
        }

        if ($request->has('payment_secret_key') && $request->payment_secret_key) {
            if (!$this->isMasked($request->payment_secret_key)) {
                Setting::set('payment_secret_key', $request->payment_secret_key, 'encrypted');
            }
        }

        if ($request->has('payment_webhook_secret') && $request->payment_webhook_secret) {
            if (!$this->isMasked($request->payment_webhook_secret)) {
                Setting::set('payment_webhook_secret', $request->payment_webhook_secret, 'encrypted');
            }
        }

        if ($request->has('payment_mode')) {
            Setting::set('payment_mode', $request->payment_mode, 'text');
        }

        return response()->json([
            'message' => 'Payment settings updated successfully',
            'settings' => $this->getSettingsArray(),
        ]);
    }

    public function deleteImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'key' => 'required|in:app_logo,app_favicon',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $setting = Setting::where('key', $request->key)->first();
        
        if ($setting && $setting->value) {
            Storage::disk('public')->delete($setting->value);
            $setting->update(['value' => null]);
        }

        return response()->json([
            'message' => 'Image deleted successfully',
        ]);
    }

    private function isMasked($value)
    {
        return str_starts_with($value ?? '', '********');
    }

    private function getSettingsArray()
    {
        $settings = Setting::all()->keyBy('key');
        $processed = [];
        
        foreach ($settings as $key => $setting) {
            $value = $setting->value;
            
            if ($setting->type === 'encrypted' && $value) {
                try {
                    $decrypted = decrypt($value);
                    $value = '********' . substr($decrypted, -4);
                    $setting->is_masked = true;
                } catch (\Exception $e) {
                    $value = null;
                }
            }
            
            $processed[$key] = [
                'id' => $setting->id,
                'value' => $value,
                'type' => $setting->type,
                'is_masked' => $setting->is_masked ?? false,
                'has_value' => !empty($setting->value),
            ];
        }
        
        return $processed;
    }
}
