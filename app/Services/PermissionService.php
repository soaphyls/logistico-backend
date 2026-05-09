<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\PermissionAuditLog;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Support\Facades\DB;

class PermissionService
{
    /**
     * Check if a user has a specific permission
     * Resolution order:
     * 1. Check personal override (grant/revoke)
     * 2. Fall back to role permissions
     */
    public function hasPermission(int $userId, string $permissionSlug): bool
    {
        $user = User::with('role.permissions')->findOrFail($userId);

        // Super admin has all permissions
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Step 1: Check personal override first
        $override = $this->getUserOverride($userId, $permissionSlug);
        
        if ($override !== null) {
            // If override is 'grant' - user has permission
            // If override is 'revoke' - user does NOT have permission
            return $override === 'grant';
        }

        // Step 2: Fall back to role permissions
        return $this->hasRolePermission($user, $permissionSlug);
    }

    /**
     * Get user's personal override for a permission
     * Returns: 'grant', 'revoke', or null (no override)
     */
    public function getUserOverride(int $userId, string $permissionSlug): ?string
    {
        $override = UserPermissionOverride::where('user_id', $userId)
            ->whereHas('permission', function ($query) use ($permissionSlug) {
                $query->where('slug', $permissionSlug);
            })
            ->first();

        return $override?->override_type;
    }

    /**
     * Check if user has permission via their role
     */
    private function hasRolePermission(User $user, string $permissionSlug): bool
    {
        if (!$user->role) {
            return false;
        }

        return $user->role->hasPermission($permissionSlug);
    }

    /**
     * Get all permissions for a user (role + overrides)
     */
    public function getUserPermissions(int $userId): array
    {
        $user = User::with('role.permissions')->findOrFail($userId);
        
        $permissions = [];
        
        // Get all available permissions
        $allPermissions = Permission::all();

        foreach ($allPermissions as $permission) {
            $slug = $permission->slug;
            $hasPermission = $this->hasPermission($userId, $slug);
            $override = $this->getUserOverride($userId, $slug);

            $permissions[$slug] = [
                'has_permission' => $hasPermission,
                'override_type' => $override, // 'grant', 'revoke', or null
                'source' => $override ? 'override' : 'role',
            ];
        }

        return $permissions;
    }

    /**
     * Get role-based permissions only (without overrides)
     */
    public function getRolePermissions(int $userId): array
    {
        $user = User::with('role.permissions')->findOrFail($userId);
        
        if (!$user->role) {
            return [];
        }

        return $user->role->permissions()->pluck('slug')->toArray();
    }

    /**
     * Get user's personal overrides
     */
    public function getUserOverrides(int $userId): array
    {
        $overrides = UserPermissionOverride::where('user_id', $userId)
            ->with('permission')
            ->get();

        $result = [];
        foreach ($overrides as $override) {
            $result[$override->permission->slug] = $override->override_type;
        }

        return $result;
    }

    /**
     * Grant a permission to a user (personal override)
     */
    public function grantPermission(int $userId, int $permissionId, int $grantedBy): UserPermissionOverride
    {
        // Check if override already exists
        $existing = UserPermissionOverride::where('user_id', $userId)
            ->where('permission_id', $permissionId)
            ->first();

        $oldValue = $existing?->override_type;

        if ($existing) {
            if ($existing->override_type === 'grant') {
                // Already granted, no change needed
                return $existing;
            }
            // Change from revoke to grant
            $existing->update(['override_type' => 'grant']);
            $this->logAudit($userId, $grantedBy, 'granted', $existing->permission->slug, $oldValue, 'grant');
            return $existing;
        }

        // Create new grant
        $override = UserPermissionOverride::create([
            'user_id' => $userId,
            'permission_id' => $permissionId,
            'override_type' => 'grant',
            'created_by' => $grantedBy,
        ]);

        $this->logAudit($userId, $grantedBy, 'granted', $override->permission->slug, null, 'grant');

        return $override;
    }

    /**
     * Revoke a permission from a user (personal override)
     */
    public function revokePermission(int $userId, int $permissionId, int $revokedBy): ?UserPermissionOverride
    {
        $existing = UserPermissionOverride::where('user_id', $userId)
            ->where('permission_id', $permissionId)
            ->first();

        $oldValue = $existing?->override_type;

        if ($existing) {
            if ($existing->override_type === 'revoke') {
                // Already revoked
                return $existing;
            }
            // Change from grant to revoke
            $existing->update(['override_type' => 'revoke']);
            $this->logAudit($userId, $revokedBy, 'revoked', $existing->permission->slug, $oldValue, 'revoke');
            return $existing;
        }

        // Create new revoke
        $permission = Permission::findOrFail($permissionId);
        $override = UserPermissionOverride::create([
            'user_id' => $userId,
            'permission_id' => $permissionId,
            'override_type' => 'revoke',
            'created_by' => $revokedBy,
        ]);

        $this->logAudit($userId, $revokedBy, 'revoked', $permission->slug, null, 'revoke');

        return $override;
    }

    /**
     * Remove an override completely (revert to role default)
     */
    public function removeOverride(int $userId, int $permissionId, int $removedBy): bool
    {
        $existing = UserPermissionOverride::where('user_id', $userId)
            ->where('permission_id', $permissionId)
            ->first();

        if (!$existing) {
            return false;
        }

        $oldValue = $existing->override_type;
        $permissionSlug = $existing->permission->slug;
        
        $existing->delete();

        $this->logAudit($userId, $removedBy, 'removed_override', $permissionSlug, $oldValue, null);

        return true;
    }

    /**
     * Update multiple permissions at once
     */
    public function updatePermissions(int $userId, array $permissions, int $updatedBy): void
    {
        $allPermissions = Permission::all()->keyBy('id');

        foreach ($permissions as $permissionId => $action) {
            if (!isset($allPermissions[$permissionId])) {
                continue;
            }

            switch ($action) {
                case 'grant':
                    $this->grantPermission($userId, $permissionId, $updatedBy);
                    break;
                case 'revoke':
                    $this->revokePermission($userId, $permissionId, $updatedBy);
                    break;
                case 'remove':
                    $this->removeOverride($userId, $permissionId, $updatedBy);
                    break;
            }
        }
    }

    /**
     * Check if user has any override
     */
    public function hasOverrides(int $userId): bool
    {
        return UserPermissionOverride::where('user_id', $userId)->exists();
    }

    /**
     * Get audit logs for a user
     */
    public function getAuditLogs(int $userId, int $limit = 50)
    {
        return PermissionAuditLog::where('user_id', $userId)
            ->with('changedBy')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Log permission change to audit table
     */
    private function logAudit(int $userId, int $changedBy, string $action, ?string $permission, ?string $oldValue, ?string $newValue): void
    {
        PermissionAuditLog::create([
            'user_id' => $userId,
            'changed_by' => $changedBy,
            'action' => $action,
            'permission' => $permission,
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ]);
    }
}
