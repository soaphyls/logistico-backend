<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\PermissionAuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\MenuVisibilityService;
use Illuminate\Http\Request;

class MenuVisibilityController extends Controller
{
    protected MenuVisibilityService $service;

    public function __construct(MenuVisibilityService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->error('Only super admins can view the menu permission matrix', 403);
        }

        $matrix = $this->service->getMatrix();

        $roleSlug = $request->query('role');
        if (is_string($roleSlug) && $roleSlug !== '') {
            $filtered = [];
            foreach ($matrix['matrix'] as $menuKey => $row) {
                $filtered[$menuKey] = [
                    $roleSlug => $row[$roleSlug] ?? false,
                ];
            }
            $matrix['matrix'] = $filtered;
        }

        return $this->success($matrix);
    }

    public function myMenu(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated', 401);
        }

        return $this->success($this->service->resolveForUser($user));
    }

    public function setRoleVisibility(Request $request, $role, $menu)
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->error('Only super admins can change role menu visibility', 403);
        }

        $validated = $request->validate([
            'visible' => 'required|boolean',
        ]);

        $roleModel = Role::findOrFail($role);
        $menuModel = MenuItem::findOrFail($menu);
        $visible = (bool) $validated['visible'];

        $this->service->setRoleVisibility($roleModel->id, $menuModel->id, $visible, $request->user()->id);

        PermissionAuditLog::create([
            'user_id' => null,
            'changed_by' => $request->user()->id,
            'action' => $visible ? 'role_menu_visible' : 'role_menu_hidden',
            'subject_type' => 'role',
            'subject_id' => $roleModel->id,
            'permission' => $menuModel->key,
            'old_value' => $visible ? '0' : '1',
            'new_value' => $visible ? '1' : '0',
            'meta' => [
                'menu_key' => $menuModel->key,
                'menu_label' => $menuModel->label,
                'role_id' => $roleModel->id,
                'role_name' => $roleModel->name,
                'role_display_name' => $roleModel->display_name,
                'visible' => $visible,
            ],
        ]);

        return $this->success([
            'role_id' => $roleModel->id,
            'menu_id' => $menuModel->id,
            'menu_key' => $menuModel->key,
            'visible' => $visible,
        ], 'Role menu visibility updated');
    }

    public function setUserOverride(Request $request, $user, $menu)
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->error('Only super admins can change user menu overrides', 403);
        }

        $targetUser = User::findOrFail($user);
        $menuModel = MenuItem::findOrFail($menu);
        $actor = $request->user();

        if ($targetUser->id === $actor->id) {
            return $this->error('Cannot modify your own menu overrides', 403);
        }

        $validated = $request->validate([
            'granted' => 'required|boolean',
        ]);

        $granted = (bool) $validated['granted'];

        $override = $this->service->setUserOverride($targetUser->id, $menuModel->id, $granted, $actor->id);

        PermissionAuditLog::create([
            'user_id' => $targetUser->id,
            'changed_by' => $actor->id,
            'action' => $granted ? 'user_menu_granted' : 'user_menu_revoked',
            'subject_type' => 'user',
            'subject_id' => $targetUser->id,
            'permission' => $menuModel->key,
            'old_value' => null,
            'new_value' => $granted ? '1' : '0',
            'meta' => [
                'menu_key' => $menuModel->key,
                'menu_label' => $menuModel->label,
                'user_id' => $targetUser->id,
                'user_name' => $targetUser->name,
                'granted' => $granted,
            ],
        ]);

        return $this->success([
            'id' => $override->id,
            'user_id' => $targetUser->id,
            'menu_id' => $menuModel->id,
            'menu_key' => $menuModel->key,
            'granted' => $granted,
        ], 'User menu override saved');
    }

    public function removeUserOverride(Request $request, $user, $menu)
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->error('Only super admins can remove user menu overrides', 403);
        }

        $targetUser = User::findOrFail($user);
        $menuModel = MenuItem::findOrFail($menu);
        $actor = $request->user();

        if ($targetUser->id === $actor->id) {
            return $this->error('Cannot modify your own menu overrides', 403);
        }

        $removed = $this->service->removeUserOverride($targetUser->id, $menuModel->id, $actor->id);

        PermissionAuditLog::create([
            'user_id' => $targetUser->id,
            'changed_by' => $actor->id,
            'action' => 'user_menu_override_removed',
            'subject_type' => 'user',
            'subject_id' => $targetUser->id,
            'permission' => $menuModel->key,
            'old_value' => null,
            'new_value' => null,
            'meta' => [
                'menu_key' => $menuModel->key,
                'menu_label' => $menuModel->label,
                'user_id' => $targetUser->id,
                'user_name' => $targetUser->name,
                'removed' => $removed,
            ],
        ]);

        return $this->success([
            'user_id' => $targetUser->id,
            'menu_id' => $menuModel->id,
            'menu_key' => $menuModel->key,
            'removed' => $removed,
        ], 'User menu override removed');
    }

    public function userMenu(Request $request, $user)
    {
        if (!$this->isSuperAdmin($request)) {
            return $this->error('Only super admins can view user menus', 403);
        }

        $targetUser = User::with('role')->findOrFail($user);

        return $this->success([
            'user' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'role' => $targetUser->role ? [
                    'id' => $targetUser->role->id,
                    'name' => $targetUser->role->name,
                    'display_name' => $targetUser->role->display_name,
                ] : null,
            ],
            'tree' => $this->service->resolveForUser($targetUser),
            'overrides' => $this->service->getUserOverrides($targetUser->id),
        ]);
    }

    public function canAccess(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated', 401);
        }

        $validated = $request->validate([
            'route' => 'required|string',
        ]);

        return $this->success([
            'route' => $validated['route'],
            'can_access' => $this->service->canAccessRoute($user, $validated['route']),
        ]);
    }

    private function isSuperAdmin(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }
        if (!$user->relationLoaded('role')) {
            $user->load('role');
        }
        return $user->role?->name === 'super_admin' || $user->role?->slug === 'super_admin';
    }
}
