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

    /**
     * Get logo as base64 - bypasses frontend CORS issues during PDF generation
     */
    public function logoBase64()
    {
        $logoUrl = null;
        
        $companySetting = \App\Models\CompanySetting::first();
        if ($companySetting && $companySetting->logo) {
            $logoUrl = $companySetting->logo;
        } else {
            $appLogo = Setting::where('key', 'app_logo')->first();
            if ($appLogo && $appLogo->value) {
                $logoUrl = $appLogo->value;
            }
        }
        
        if (!$logoUrl) {
            return response()->json(['error' => 'Logo not found'], 404);
        }
        
        // Build a list of candidate paths to try, from most to least likely
        $candidates = [];
        
        if (str_starts_with($logoUrl, 'http')) {
            // Full URL — extract path component
            $path = ltrim(parse_url($logoUrl, PHP_URL_PATH), '/');
            $candidates[] = public_path($path);
            // Also try with storage/ prefix if not already there
            if (!str_starts_with($path, 'storage/')) {
                $candidates[] = public_path('storage/' . $path);
            }
        } else {
            // Relative path — strip any leading ~, / characters
            $cleanPath = ltrim(str_replace(['~', '\\'], ['', '/'], $logoUrl), '/');
            
            // Try the path as-is
            $candidates[] = public_path($cleanPath);
            // Try prepending storage/
            if (!str_starts_with($cleanPath, 'storage/')) {
                $candidates[] = public_path('storage/' . $cleanPath);
            }
            // Try prepending uploads/
            if (!str_starts_with($cleanPath, 'uploads/')) {
                $candidates[] = public_path('uploads/' . $cleanPath);
            }
            // Try prepending uploads/settings/
            if (!str_starts_with($cleanPath, 'uploads/settings/')) {
                $filename = basename($cleanPath);
                $candidates[] = public_path('uploads/settings/' . $filename);
            }
            // Try in storage/app/public
            $candidates[] = storage_path('app/public/' . $cleanPath);
        }
        
        $filePath = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $filePath = $candidate;
                break;
            }
        }
        
        if ($filePath) {
            $mimeType = mime_content_type($filePath);
            $base64 = base64_encode(file_get_contents($filePath));
            return response()->json([
                'base64' => 'data:' . $mimeType . ';base64,' . $base64,
                'mime_type' => $mimeType,
                'format' => str_contains($mimeType, 'png') ? 'PNG' : 'JPEG'
            ]);
        }
        
        return response()->json([
            'error' => 'Logo file does not exist on disk',
            'tried' => $candidates,
            'stored_value' => $logoUrl,
        ], 404);
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
        $validator = Validator::make($request->all(), [
            'app_name'     => 'nullable|string|max:255',
            'primary_color'=> 'nullable|string|max:20',
            'app_currency' => 'nullable|string|max:3',
            'app_logo'     => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'app_favicon'  => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,ico|max:512',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->filled('app_name')) {
            Setting::set('app_name', $request->app_name, 'text');
        }

        if ($request->filled('primary_color')) {
            Setting::set('primary_color', $request->primary_color, 'text');
        }

        if ($request->filled('app_currency')) {
            Setting::set('app_currency', $request->app_currency, 'text');
        }

        // Shared-hosting safe image upload:
        // Store directly in public/uploads/settings/ — no storage:link required.
        $uploadsDir = public_path('uploads/settings');
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }

        $baseUrl = rtrim(config('app.url'), '/');

        $logoFile = $request->file('app_logo');
        if ($logoFile && $logoFile->isValid()) {
            $ext      = $logoFile->getClientOriginalExtension();
            $filename = 'logo_' . time() . '.' . $ext;
            $logoFile->move($uploadsDir, $filename);
            $logoUrl  = $baseUrl . '/uploads/settings/' . $filename;
            Setting::set('app_logo', $logoUrl, 'image');
        }

        $faviconFile = $request->file('app_favicon');
        if ($faviconFile && $faviconFile->isValid()) {
            $ext      = $faviconFile->getClientOriginalExtension();
            $filename = 'favicon_' . time() . '.' . $ext;
            $faviconFile->move($uploadsDir, $filename);
            $faviconUrl = $baseUrl . '/uploads/settings/' . $filename;
            Setting::set('app_favicon', $faviconUrl, 'image');
        }

        return response()->json([
            'message'  => 'General settings updated successfully',
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
            // Value is now stored as a full URL; derive the local filesystem path
            $relativePath = ltrim(parse_url($setting->value, PHP_URL_PATH), '/');
            $fullPath = public_path($relativePath);
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
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
