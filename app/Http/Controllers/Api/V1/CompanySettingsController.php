<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use Illuminate\Http\Request;

class CompanySettingsController extends Controller
{
    public function index()
    {
        $settings = CompanySetting::getSettings();
        return $this->success($settings);
    }

    public function update(Request $request)
    {
        $user = auth()->user();
        
        if (!$user) {
            return $this->error('Unauthorized', 401);
        }
        
        // Use role slug to check
        if (!$user->role || $user->role->name !== 'super_admin') {
            return $this->error('Only administrators can update company settings', 403);
        }

        $validated = $request->validate([
            'company_name' => 'sometimes|string|max:255',
            'tracking_prefix' => 'sometimes|string|max:10|regex:/^[A-Z0-9]+$/',
            'country' => 'nullable|string|max:120',
            'state' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'account_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $settings = CompanySetting::getSettings();
        $settings->update($validated);

        return $this->success($settings, 'Company settings updated successfully');
    }
}
