<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Role;
use App\Models\RoleMenuVisibility;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MenuItemsSeeder extends Seeder
{
    public function run(): void
    {
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'menu_registry.json';

        if (!file_exists($path)) {
            $this->command?->warn("menu_registry.json not found at {$path}");
            return;
        }

        $entries = json_decode((string) file_get_contents($path), true);
        if (!is_array($entries)) {
            $this->command?->error('menu_registry.json is not valid JSON');
            return;
        }

        $menuCount = 0;
        foreach ($entries as $entry) {
            MenuItem::updateOrCreate(
                ['key' => $entry['key']],
                [
                    'label' => $entry['label'] ?? $entry['key'],
                    'route' => $entry['route'] ?? null,
                    'parent_key' => $entry['parent_key'] ?? null,
                    'icon_name' => $entry['icon_name'] ?? null,
                    'sort_order' => $entry['sort_order'] ?? 0,
                ]
            );
            $menuCount++;
        }

        $visibilityCount = 0;
        $rolesBySlug = Role::all()->keyBy('slug');
        foreach ($rolesBySlug as $role) {
            $rolesBySlug->put(str_replace('-', '_', $role->slug), $role);
        }
        $menusByKey = MenuItem::all()->keyBy('key');

        foreach ($entries as $entry) {
            $menu = $menusByKey[$entry['key']] ?? null;
            if (!$menu) {
                continue;
            }
            foreach ($entry['default_roles'] ?? [] as $slug) {
                $role = $rolesBySlug[$slug]
                    ?? $rolesBySlug[str_replace('_', '-', $slug)]
                    ?? null;
                if (!$role) {
                    continue;
                }
                $created = RoleMenuVisibility::firstOrCreate([
                    'role_id' => $role->id,
                    'menu_item_id' => $menu->id,
                ]);
                if ($created->wasRecentlyCreated) {
                    $visibilityCount++;
                }
            }
        }

        $this->command?->info("Seeded {$menuCount} menu items and {$visibilityCount} role-visibility defaults.");
    }
}
