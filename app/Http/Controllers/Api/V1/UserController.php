<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PartnerCustomer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('role');

        if ($request->has('role')) {
            $query->whereHas('role', function ($q) use ($request) {
                $q->where('name', $request->role);
            });

            // When listing partners, include product counts
            if ($request->role === 'partner') {
                $query->withCount('partnerProducts as products');
            }
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $users = $query->orderBy('created_at', 'desc')->paginate(20);

        return $this->success($users);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:20',
            'company' => 'nullable|string|max:255',
            'role_id' => 'required|exists:roles,id',
            'is_active' => 'boolean',
        ]);

        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);
        
        // Auto-create dispatcher record if role is dispatcher
        $role = \App\Models\Role::find($validated['role_id']);
        if ($role && $role->slug === 'driver') {
            \App\Models\Dispatcher::create([
                'user_id' => $user->id,
                'license_number' => 'PENDING',
                'license_expiry' => now()->addYear(),
                'is_available' => false,
            ]);
        }
        
        $user->load('role');

        return $this->success($user, 'User created successfully', 201);
    }

    public function show(User $user)
    {
        $user->load(['role', 'dispatcher', 'assignedStaff']);
        
        return $this->success($user);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'company' => 'nullable|string|max:255',
            'role_id' => 'sometimes|exists:roles,id',
            'is_active' => 'sometimes|boolean',
            'assigned_staff_id' => 'nullable|exists:users,id',
        ]);

        if ($request->has('password') && $request->password) {
            $validated['password'] = Hash::make($request->password);
        }

        $user->update($validated);

        // Keep partner profile assignment in sync with bot order lookup source.
        if ($request->has('assigned_staff_id')) {
            $isPartner = ($user->role?->name === 'partner') || ($user->role?->slug === 'partner');
            if ($isPartner) {
                PartnerCustomer::where('partner_id', $user->id)->update([
                    'staff_id' => $validated['assigned_staff_id'] ?? null,
                ]);
            }
        }

        $user->load(['role', 'assignedStaff']);

        return $this->success($user, 'User updated successfully');
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return $this->error('You cannot delete your own account', 400);
        }

        $user->delete();

        return $this->success(null, 'User deleted successfully');
    }

    public function toggleStatus(User $user)
    {
        if ($user->id === auth()->id()) {
            return $this->error('You cannot toggle your own status', 400);
        }

        $user->update(['is_active' => !$user->is_active]);

        return $this->success($user, 'User status updated');
    }

    public function resetPassword(Request $request, User $user)
    {
        $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return $this->success(null, 'Password reset successfully');
    }
}
