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

        $faviconSetting = $settings['app_favicon'] ?? null;
        $faviconHasBinary = !empty($faviconSetting?->binary_value);
        $faviconUrl = $faviconHasBinary ? url('/api/v1/settings/favicon') : null;

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
                    'value' => $faviconUrl,
                    'has_value' => $faviconHasBinary,
                    'mime_type' => $faviconSetting?->mime_type,
                ],
                'primary_color' => [
                    'value' => $settings['primary_color']?->value ?? '#f97316',
                    'has_value' => !empty($settings['primary_color']?->value),
                ],
            ],
        ]);
    }

    /**
     * Stream the favicon binary stored in the database.
     * Public endpoint — no auth required.
     */
    public function serveFavicon(Request $request)
    {
        $favicon = Setting::where('key', 'app_favicon')->first();

        if (!$favicon || empty($favicon->binary_value)) {
            return response('', 404);
        }

        $mimeType = $favicon->mime_type ?: 'image/x-icon';

        return response($favicon->binary_value, 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=3600',
            'Access-Control-Allow-Origin' => '*',
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

        // Try R2 storage first (production)
        if (env('FILESYSTEM_DISK') === 'r2') {
            $path = ltrim(parse_url($logoUrl, PHP_URL_PATH), '/');
            try {
                if ($path && Storage::disk('r2')->exists($path)) {
                    $contents = Storage::disk('r2')->get($path);
                    $mimeType = mime_content_type(tempnam(sys_get_temp_dir(), 'logo'));
                    // Detect from extension fallback
                    $ext = pathinfo($path, PATHINFO_EXTENSION);
                    $mimeMap = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
                    $mimeType = $mimeMap[$ext] ?? 'image/png';
                    $base64 = base64_encode($contents);
                    return response()->json([
                        'base64' => 'data:' . $mimeType . ';base64,' . $base64,
                        'mime_type' => $mimeType,
                        'format' => str_contains($mimeType, 'png') ? 'PNG' : 'JPEG',
                    ]);
                }
            } catch (\Exception $e) {
                // Fall through to local
            }
        }
        
        // Fallback: try local filesystem (development)
        $candidates = [];
        
        if (str_starts_with($logoUrl, 'http')) {
            $path = ltrim(parse_url($logoUrl, PHP_URL_PATH), '/');
            $candidates[] = public_path($path);
            if (!str_starts_with($path, 'storage/')) {
                $candidates[] = public_path('storage/' . $path);
            }
        } else {
            $cleanPath = ltrim(str_replace(['~', '\\'], ['', '/'], $logoUrl), '/');
            $candidates[] = public_path($cleanPath);
            if (!str_starts_with($cleanPath, 'storage/')) {
                $candidates[] = public_path('storage/' . $cleanPath);
            }
            if (!str_starts_with($cleanPath, 'uploads/')) {
                $candidates[] = public_path('uploads/' . $cleanPath);
            }
            $filename = basename($cleanPath);
            $candidates[] = public_path('uploads/settings/' . $filename);
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
                'format' => str_contains($mimeType, 'png') ? 'PNG' : 'JPEG',
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

        $logoFile = $request->file('app_logo');
        if ($logoFile && $logoFile->isValid()) {
            $ext      = $logoFile->getClientOriginalExtension();
            $filename = 'logo_' . time() . '.' . $ext;
            if (env('FILESYSTEM_DISK') === 'r2') {
                Storage::disk('r2')->putFileAs('logos', $logoFile, $filename, 'public');
                $logoUrl = Storage::disk('r2')->url('logos/' . $filename);
            } else {
                $logoFile->move(public_path('uploads/settings'), $filename);
                $logoUrl = rtrim(config('app.url'), '/') . '/uploads/settings/' . $filename;
            }
            Setting::set('app_logo', $logoUrl, 'image');
        }

        $faviconFile = $request->file('app_favicon');
        if ($faviconFile && $faviconFile->isValid()) {
            $binary = file_get_contents($faviconFile->getRealPath());
            $mimeType = $faviconFile->getMimeType() ?: 'image/x-icon';
            if ($binary !== false) {
                Setting::setBinary('app_favicon', $binary, $mimeType);
            }
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

        if ($setting) {
            if ($request->key === 'app_favicon') {
                // Favicon is stored as a BLOB in the database
                $setting->update([
                    'value' => null,
                    'binary_value' => null,
                    'mime_type' => null,
                ]);
            } elseif ($setting->value) {
                $path = ltrim(parse_url($setting->value, PHP_URL_PATH), '/');
                if (env('FILESYSTEM_DISK') === 'r2') {
                    Storage::disk('r2')->delete($path);
                } else {
                    $fullPath = public_path($path);
                    if (file_exists($fullPath)) {
                        unlink($fullPath);
                    }
                }
                $setting->update(['value' => null]);
            }
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
