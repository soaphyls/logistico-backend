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

        $perPage = $request->integer('per_page', 10);
        if ($perPage < 1 || $perPage > 100) {
            $perPage = 10;
        }

        $users = $query->orderBy('created_at', 'desc')->paginate($perPage);

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
            'bank_name' => 'nullable|string|max:255',
            'bank_account_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'role_id' => 'required_without:role|nullable|integer|exists:roles,id',
            'role' => 'required_without:role_id|nullable|string|exists:roles,name',
            'is_active' => 'boolean',
        ]);

        if (empty($validated['role_id']) && !empty($validated['role'])) {
            $validated['role_id'] = Role::where('name', $validated['role'])->value('id');
        }
        unset($validated['role']);

        if ($request->hasFile('company_logo')) {
            $request->validate(['company_logo' => 'image|mimes:jpeg,png,jpg,gif|max:2048']);
            $uploadsDir = public_path('uploads/partners');
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0755, true);
            }
            $ext      = $request->file('company_logo')->getClientOriginalExtension();
            $filename = 'logo_' . time() . '.' . $ext;
            $request->file('company_logo')->move($uploadsDir, $filename);
            $logoUrl  = rtrim(config('app.url'), '/') . '/uploads/partners/' . $filename;
            $validated['company_logo'] = $logoUrl;
            $validated['avatar']       = $logoUrl;
        }

        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);
        
        // Auto-create dispatcher record if role is dispatcher
        $role = \App\Models\Role::find($validated['role_id']);
        if ($role && $role->slug === 'dispatcher') {
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

        // company_logo is now stored as a full URL; only build one if it's a legacy relative path
        if ($user->company_logo && !str_starts_with($user->company_logo, 'http')) {
            $user->company_logo_url = asset($user->company_logo);
        } else {
            $user->company_logo_url = $user->company_logo;
        }

        return $this->success($user);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'company' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'role_id' => 'sometimes|exists:roles,id',
            'is_active' => 'sometimes|boolean',
            'assigned_staff_id' => 'nullable|exists:users,id',
        ]);

        if ($request->hasFile('company_logo')) {
            $request->validate(['company_logo' => 'image|mimes:jpeg,png,jpg,gif|max:2048']);
            $uploadsDir = public_path('uploads/partners');
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0755, true);
            }
            $ext      = $request->file('company_logo')->getClientOriginalExtension();
            $filename = 'logo_' . time() . '.' . $ext;
            $request->file('company_logo')->move($uploadsDir, $filename);
            $logoUrl  = rtrim(config('app.url'), '/') . '/uploads/partners/' . $filename;
            $validated['company_logo'] = $logoUrl;
            $validated['avatar']       = $logoUrl;
        }

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

    public function listStaff(Request $request, User $partner)
    {
        $staff = User::with('role')
            ->where('parent_id', $partner->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->makeHidden(['password', 'remember_token']);

        return $this->success($staff);
    }

    public function storeStaff(Request $request, User $partner)
    {
        $this->ensurePartnerIsParent($partner);

        $validated = $request->validate([
            'staff' => 'required|array|min:1|max:5',
            'staff.*.name' => 'required|string|max:255',
            'staff.*.email' => 'required|email|distinct|unique:users,email',
            'staff.*.password' => 'required|string|min:8',
        ]);

        $role = Role::where('name', 'partner_staff')->first();
        if (!$role) {
            return $this->error('partner_staff role is missing. Run php artisan db:seed --class=RoleSeeder', 500);
        }

        $created = [];
        foreach ($validated['staff'] as $row) {
            $created[] = User::create([
                'name' => $row['name'],
                'email' => $row['email'],
                'password' => Hash::make($row['password']),
                'role_id' => $role->id,
                'parent_id' => $partner->id,
                'is_active' => true,
            ])->load('role');
        }

        return $this->success($created, count($created) . ' staff account(s) created', 201);
    }

    public function updateStaff(Request $request, User $partner, User $staff)
    {
        $this->ensureStaffBelongsToPartner($partner, $staff);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $staff->id,
            'password' => 'sometimes|string|min:8',
            'is_active' => 'sometimes|boolean',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $staff->update($validated);

        return $this->success($staff->fresh('role'), 'Staff account updated');
    }

    public function destroyStaff(User $partner, User $staff)
    {
        $this->ensureStaffBelongsToPartner($partner, $staff);

        if ($staff->id === auth()->id()) {
            return $this->error('You cannot delete your own account', 400);
        }

        $staff->delete();

        return $this->success(null, 'Staff account deleted');
    }

    private function ensurePartnerIsParent(User $partner): void
    {
        $isPartner = $partner->role && (
            ($partner->role->name ?? null) === 'partner' ||
            ($partner->role->slug ?? null) === 'partner'
        );
        if (!$isPartner) {
            abort(422, 'Target user is not a partner account');
        }
    }

    private function ensureStaffBelongsToPartner(User $partner, User $staff): void
    {
        if ((int) $staff->parent_id !== (int) $partner->id) {
            abort(404, 'Staff account not found for this partner');
        }
    }
}
