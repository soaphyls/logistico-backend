<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\Role;
use App\Models\RoleMenuVisibility;
use App\Models\User;
use App\Models\UserMenuOverride;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class MenuVisibilityService
{
    private const CACHE_TTL_SECONDS = 300;

    public function resolveForUser(User $user): array
    {
        $cacheKey = $this->cacheKey($user->id, (int) $user->role_id);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($user) {
            $allItems = MenuItem::ordered()->get();

            $roleVisibleIds = RoleMenuVisibility::where('role_id', $user->role_id)
                ->pluck('menu_item_id')
                ->all();

            $userOverrides = UserMenuOverride::where('user_id', $user->id)->get();

            return $this->buildTree($allItems, $this->effectiveMap($allItems, $roleVisibleIds, $userOverrides));
        });
    }

    public function getMatrix(): array
    {
        $menus = MenuItem::ordered()->get()->map(function (MenuItem $item) {
            return [
                'id' => $item->id,
                'key' => $item->key,
                'label' => $item->label,
                'parent_key' => $item->parent_key,
                'icon_name' => $item->icon_name,
                'sort_order' => (int) $item->sort_order,
            ];
        })->all();

        $roles = Role::orderBy('id')->get()->map(function (Role $role) {
            return [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
            ];
        })->all();

        $visibilityRows = RoleMenuVisibility::all(['role_id', 'menu_item_id']);
        $visiblePairs = [];
        foreach ($visibilityRows as $row) {
            $visiblePairs[$row->role_id][$row->menu_item_id] = true;
        }

        $menuById = [];
        $roleById = [];
        foreach ($menus as $m) {
            $menuById[$m['id']] = $m;
        }
        foreach ($roles as $r) {
            $roleById[$r['id']] = $r;
        }

        $matrix = [];
        foreach ($menus as $m) {
            $row = [];
            foreach ($roles as $r) {
                $row[$r['name']] = isset($visiblePairs[$r['id']][$m['id']]);
            }
            $matrix[$m['key']] = $row;
        }

        $userOverrides = UserMenuOverride::with(['menuItem', 'user'])->get();
        $grouped = [];
        foreach ($userOverrides as $override) {
            $grouped[$override->user_id][] = $override;
        }

        $usersWithOverrides = [];
        foreach ($grouped as $userId => $overrides) {
            $user = $overrides[0]->user;
            $menuKeys = [];
            foreach ($overrides as $o) {
                if ($o->menuItem) {
                    $menuKeys[] = $o->menuItem->key;
                }
            }
            $usersWithOverrides[] = [
                'user_id' => (int) $userId,
                'user_name' => $user?->name ?? 'Unknown',
                'override_count' => count($overrides),
                'menu_keys' => array_values(array_unique($menuKeys)),
            ];
        }

        usort($usersWithOverrides, function ($a, $b) {
            return $a['user_id'] <=> $b['user_id'];
        });

        return [
            'menus' => $menus,
            'roles' => $roles,
            'matrix' => $matrix,
            'users_with_overrides' => $usersWithOverrides,
        ];
    }

    public function setRoleVisibility(int $roleId, int $menuItemId, bool $visible, int $grantedBy): void
    {
        if ($visible) {
            RoleMenuVisibility::firstOrCreate(
                [
                    'role_id' => $roleId,
                    'menu_item_id' => $menuItemId,
                ],
                [
                    'granted_by' => $grantedBy,
                ]
            );
        } else {
            RoleMenuVisibility::where('role_id', $roleId)
                ->where('menu_item_id', $menuItemId)
                ->delete();
        }

        $this->flushCache(0, $roleId);
    }

    public function setUserOverride(int $userId, int $menuItemId, bool $granted, int $createdBy): UserMenuOverride
    {
        $override = UserMenuOverride::updateOrCreate(
            [
                'user_id' => $userId,
                'menu_item_id' => $menuItemId,
            ],
            [
                'granted' => $granted,
                'created_by' => $createdBy,
            ]
        );

        $this->flushCache($userId);

        return $override;
    }

    public function removeUserOverride(int $userId, int $menuItemId, int $removedBy): bool
    {
        $deleted = UserMenuOverride::where('user_id', $userId)
            ->where('menu_item_id', $menuItemId)
            ->delete();

        $this->flushCache($userId);

        return $deleted > 0;
    }

    public function canAccessRoute(User $user, string $route): bool
    {
        $tree = $this->resolveForUser($user);
        $normalized = '/' . ltrim($route, '/');

        foreach ($tree as $node) {
            if ($this->nodeHasRoute($node, $normalized)) {
                return true;
            }
        }

        return false;
    }

    public function getUserOverrides(int $userId): array
    {
        $overrides = UserMenuOverride::where('user_id', $userId)
            ->with('menuItem')
            ->get();

        $result = [];
        foreach ($overrides as $override) {
            if ($override->menuItem) {
                $result[$override->menuItem->key] = (bool) $override->granted;
            }
        }

        return $result;
    }

    public function flushCache(int $userId, ?int $roleId = null): void
    {
        if ($userId > 0) {
            $user = User::find($userId);
            if ($user) {
                Cache::forget($this->cacheKey($user->id, (int) $user->role_id));
            }
        }

        if ($roleId !== null && $roleId > 0) {
            $userIds = User::where('role_id', $roleId)->pluck('id');
            foreach ($userIds as $uid) {
                Cache::forget($this->cacheKey((int) $uid, $roleId));
            }
        }
    }

    private function cacheKey(int $userId, int $roleId): string
    {
        return "menu_eff:{$userId}:{$roleId}";
    }

    private function effectiveMap(Collection $allItems, array $roleVisibleIds, Collection $userOverrides): array
    {
        $overrideByItemId = [];
        foreach ($userOverrides as $override) {
            $overrideByItemId[$override->menu_item_id] = (bool) $override->granted;
        }

        $map = [];
        foreach ($allItems as $item) {
            if (array_key_exists($item->id, $overrideByItemId)) {
                $map[$item->id] = $overrideByItemId[$item->id];
            } else {
                $map[$item->id] = in_array($item->id, $roleVisibleIds, true);
            }
        }
        return $map;
    }

    private function buildTree(Collection $allItems, array $effective): array
    {
        $byKey = [];
        $childrenByKey = [];
        foreach ($allItems as $item) {
            $byKey[$item->key] = $item;
            if ($item->parent_key) {
                $childrenByKey[$item->parent_key][] = $item->key;
            }
        }

        $computeVisible = function (string $key) use (&$computeVisible, $byKey, $childrenByKey, $effective): bool {
            $item = $byKey[$key] ?? null;
            if (!$item) {
                return false;
            }
            $childKeys = $childrenByKey[$key] ?? [];
            if (empty($childKeys)) {
                return $effective[$item->id] ?? false;
            }
            foreach ($childKeys as $childKey) {
                if ($computeVisible($childKey)) {
                    return true;
                }
            }
            return false;
        };

        $build = function (string $key) use (&$build, $byKey, $childrenByKey, $computeVisible): ?array {
            $item = $byKey[$key];
            $children = [];
            foreach ($childrenByKey[$key] ?? [] as $childKey) {
                $child = $build($childKey);
                if ($child !== null) {
                    $children[] = $child;
                }
            }
            if (!$computeVisible($key)) {
                return null;
            }
            return [
                'key' => $item->key,
                'label' => $item->label,
                'route' => $item->route,
                'icon_name' => $item->icon_name,
                'children' => array_values($children),
            ];
        };

        $tree = [];
        foreach ($allItems as $item) {
            if (is_null($item->parent_key)) {
                $node = $build($item->key);
                if ($node !== null) {
                    $tree[] = $node;
                }
            }
        }

        return array_values($tree);
    }

    private function nodeHasRoute(array $node, string $route): bool
    {
        if (!empty($node['route']) && $node['route'] === $route) {
            return true;
        }
        foreach ($node['children'] ?? [] as $child) {
            if ($this->nodeHasRoute($child, $route)) {
                return true;
            }
        }
        return false;
    }
}
