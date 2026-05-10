<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($credentials)) {
            return $this->error('Invalid credentials', 401);
        }

        $user = Auth::user();

        if (!$user->is_active) {
            return $this->error('Account is inactive', 401);
        }

        if ($user->role && $user->role->slug === 'partner') {
            return $this->error('Partners cannot access this portal. Please use the partner portal.', 403);
        }

        $user->update(['last_login_at' => now()]);

        $user->load('role');

        $token = $user->createToken('api-token')->plainTextToken;

        return $this->success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'nickname' => $user->nickname,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar' => $user->avatar ? asset($user->avatar) : null,
                'is_active' => $user->is_active,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
                'slug' => $user->role->slug,
                'display_name' => $user->role->display_name,
            ] : null,
            ],
            'token' => $token,
        ], 'Login successful');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return $this->success(null, 'Logged out successfully');
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $user->load('role');

        return $this->success([
            'id' => $user->id,
            'name' => $user->name,
            'nickname' => $user->nickname,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar' => $user->avatar ? asset($user->avatar) : null,
            'is_active' => $user->is_active,
            'last_login_at' => $user->last_login_at,
            'created_at' => $user->created_at,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
                'slug' => $user->role->slug,
                'display_name' => $user->role->display_name,
            ] : null,
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'nickname' => 'nullable|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
        ]);

        if ($request->hasFile('avatar')) {
            $request->validate(['avatar' => 'image|mimes:jpeg,png,jpg,gif|max:2048']);
            $path = $request->file('avatar')->store('avatars', 'public');
            $validated['avatar'] = 'storage/' . $path; // Store relative path in DB
        }

        $user->update($validated);
        $user->load('role');

        return $this->success([
            'id' => $user->id,
            'name' => $user->name,
            'nickname' => $user->nickname,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar' => $user->avatar ? asset($user->avatar) : null,
            'is_active' => $user->is_active,
            'last_login_at' => $user->last_login_at,
            'created_at' => $user->created_at,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
                'slug' => $user->role->slug,
                'display_name' => $user->role->display_name,
            ] : null,
        ], 'Profile updated successfully');
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return $this->error('Current password is incorrect', 422);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return $this->success(null, 'Password changed successfully');
    }
}
