<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PermissionController extends Controller
{
    protected $permissionService;

    public function __construct()
    {
        $this->permissionService = app(PermissionService::class);
    }

    /**
     * Get all permissions grouped by category
     */
    public function permissions()
    {
        $permissions = Permission::orderBy('category')->orderBy('name')->get()->groupBy('category');
        
        return $this->success($permissions);
    }

    /**
     * Get all roles
     */
    public function roles()
    {
        $roles = Role::with('permissions')->get();
        
        return $this->success($roles);
    }

    /**
     * Get users with their roles and override status
     */
    public function users(Request $request)
    {
        $query = User::with('role');

        if ($request->has('role')) {
            $query->where('role_id', $request->role);
        }

        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            });
        }

        $users = $query->orderBy('name')->paginate(20);

        // Add override indicator to each user
        $users->getCollection()->transform(function ($user) {
            $user->has_overrides = $this->permissionService->hasOverrides($user->id);
            return $user;
        });

        return $this->success($users);
    }

    /**
     * Get single user's full permission breakdown
     */
    public function userPermissions(int $user)
    {
        $user = User::with('role.permissions')->findOrFail($user);

        // Prevent admin from editing their own permissions
        if ($user->isSuperAdmin()) {
            return $this->error('Cannot modify super admin permissions', 403);
        }

        $rolePermissions = $this->permissionService->getRolePermissions($user->id);
        $overrides = $this->permissionService->getUserOverrides($user->id);
        $allPermissions = Permission::orderBy('category')->orderBy('name')->get()->groupBy('category');
        
        // Build detailed permission breakdown
        $permissionBreakdown = [];
        
        foreach ($allPermissions as $category => $permissions) {
            $permissionBreakdown[$category] = $permissions->map(function ($permission) use ($user, $rolePermissions, $overrides) {
                $slug = $permission->slug;
                $hasOverride = isset($overrides[$slug]);
                $overrideType = $overrides[$slug] ?? null;
                $inRole = in_array($slug, $rolePermissions);
                
                // Calculate effective permission
                if ($hasOverride) {
                    $effective = $overrideType === 'grant';
                } else {
                    $effective = $inRole;
                }

                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'slug' => $permission->slug,
                    'description' => $permission->description,
                    'in_role' => $inRole,
                    'has_override' => $hasOverride,
                    'override_type' => $overrideType, // 'grant', 'revoke', or null
                    'effective' => $effective, // what the user actually has
                ];
            })->values();
        }

        $auditLogs = $this->permissionService->getAuditLogs($user->id);

        return $this->success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'has_overrides' => $this->permissionService->hasOverrides($user->id),
            ],
            'role_permissions' => $rolePermissions,
            'overrides' => $overrides,
            'permissions' => $permissionBreakdown,
            'audit_logs' => $auditLogs,
        ]);
    }

    /**
     * Update user's personal permission overrides
     */
    public function updateUserPermissions(Request $request, int $user)
    {
        $targetUser = User::findOrFail($user);
        $currentUser = Auth::user();

        // Prevent admin from editing their own permissions
        if ($targetUser->isSuperAdmin()) {
            return $this->error('Cannot modify super admin permissions', 403);
        }

        // Prevent non-admins from editing anyone
        if (!$currentUser->isSuperAdmin()) {
            return $this->error('Only super admins can modify permissions', 403);
        }

        // Prevent editing self
        if ($targetUser->id === $currentUser->id) {
            return $this->error('Cannot modify your own permissions', 403);
        }

        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*.permission_id' => 'required|exists:permissions,id',
            'permissions.*.action' => 'required|in:grant,revoke,remove',
        ]);

        $this->permissionService->updatePermissions(
            $targetUser->id,
            collect($validated['permissions'])->pluck('action', 'permission_id')->toArray(),
            $currentUser->id
        );

        return $this->success(null, 'Permissions updated successfully');
    }

    /**
     * Grant a specific permission to a user
     */
    public function grantPermission(Request $request, int $user)
    {
        $targetUser = User::findOrFail($user);
        $currentUser = Auth::user();

        if ($targetUser->isSuperAdmin() || $targetUser->id === $currentUser->id || !$currentUser->isSuperAdmin()) {
            return $this->error('Cannot modify these permissions', 403);
        }

        $validated = $request->validate([
            'permission_id' => 'required|exists:permissions,id',
        ]);

        $this->permissionService->grantPermission(
            $targetUser->id,
            $validated['permission_id'],
            $currentUser->id
        );

        return $this->success(null, 'Permission granted successfully');
    }

    /**
     * Revoke a specific permission from a user
     */
    public function revokePermission(Request $request, int $user)
    {
        $targetUser = User::findOrFail($user);
        $currentUser = Auth::user();

        if ($targetUser->isSuperAdmin() || $targetUser->id === $currentUser->id || !$currentUser->isSuperAdmin()) {
            return $this->error('Cannot modify these permissions', 403);
        }

        $validated = $request->validate([
            'permission_id' => 'required|exists:permissions,id',
        ]);

        $this->permissionService->revokePermission(
            $targetUser->id,
            $validated['permission_id'],
            $currentUser->id
        );

        return $this->success(null, 'Permission revoked successfully');
    }

    /**
     * Remove override (revert to role default)
     */
    public function removeOverride(Request $request, int $user)
    {
        $targetUser = User::findOrFail($user);
        $currentUser = Auth::user();

        if ($targetUser->isSuperAdmin() || $targetUser->id === $currentUser->id || !$currentUser->isSuperAdmin()) {
            return $this->error('Cannot modify these permissions', 403);
        }

        $validated = $request->validate([
            'permission_id' => 'required|exists:permissions,id',
        ]);

        $this->permissionService->removeOverride(
            $targetUser->id,
            $validated['permission_id'],
            $currentUser->id
        );

        return $this->success(null, 'Override removed successfully');
    }

    /**
     * Check if current user has a specific permission
     */
    public function checkPermission(Request $request)
    {
        $validated = $request->validate([
            'permission' => 'required|string',
        ]);

        $hasPermission = Auth::user()->hasPermission($validated['permission']);

        return $this->success([
            'has_permission' => $hasPermission,
            'permission' => $validated['permission'],
        ]);
    }
}
