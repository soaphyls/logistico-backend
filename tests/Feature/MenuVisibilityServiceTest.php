<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Role;
use App\Models\RoleMenuVisibility;
use App\Models\User;
use App\Models\UserMenuOverride;
use App\Services\MenuVisibilityService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class MenuVisibilityServiceTest extends TestCase
{
    private MenuVisibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropAndRecreateSchema();

        $this->service = new MenuVisibilityService();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();

        parent::tearDown();
    }

    public function test_super_admin_sees_everything(): void
    {
        $this->createMenu('dashboard', 'Dashboard', '/dashboard', null, 'LayoutDashboard', 0);
        $this->createMenu('operations', 'Operations', null, null, 'ShoppingCart', 10);
        $this->createMenu('operations.reconciliation', 'Reconciliation', '/operations/reconciliation', 'operations', null, 0);
        $this->createMenu('settings', 'Settings', '/settings', null, 'Settings', 20);

        $user = $this->createUserWithRole('super_admin');
        $superRole = Role::where('name', 'super_admin')->first();

        foreach (MenuItem::all() as $menu) {
            RoleMenuVisibility::firstOrCreate([
                'role_id' => $superRole->id,
                'menu_item_id' => $menu->id,
            ]);
        }

        $tree = $this->service->resolveForUser($user);

        $this->assertCount(3, $tree, 'Super admin should see 3 root menus.');
        $rootKeys = array_column($tree, 'key');
        $this->assertEquals(['dashboard', 'operations', 'settings'], $rootKeys);

        $operationsNode = $this->findNode($tree, 'operations');
        $this->assertNotNull($operationsNode);
        $this->assertCount(1, $operationsNode['children']);
        $this->assertEquals('operations.reconciliation', $operationsNode['children'][0]['key']);
    }

    public function test_role_visibility_hides_menus_by_default(): void
    {
        $this->createMenu('dashboard', 'Dashboard', '/dashboard', null, 'LayoutDashboard', 0);
        $this->createMenu('settings', 'Settings', '/settings', null, 'Settings', 10);

        $user = $this->createUserWithRole('accountant');

        $tree = $this->service->resolveForUser($user);

        $this->assertSame([], $tree, 'User with no role visibility rows should see no menus.');
    }

    public function test_role_visibility_grants_menus(): void
    {
        $this->createMenu('dashboard', 'Dashboard', '/dashboard', null, 'LayoutDashboard', 0);
        $this->createMenu('operations', 'Operations', null, null, 'ShoppingCart', 10);
        $this->createMenu('operations.dashboard', 'Dashboard', '/operations', 'operations', null, 0);
        $this->createMenu('operations.reconciliation', 'Reconciliation', '/operations/reconciliation', 'operations', null, 10);

        $role = Role::create(['name' => 'tester', 'display_name' => 'Tester']);
        $user = $this->createUserWithRole('tester');

        $opsChild = MenuItem::where('key', 'operations.dashboard')->first();
        RoleMenuVisibility::create([
            'role_id' => $role->id,
            'menu_item_id' => $opsChild->id,
        ]);

        $tree = $this->service->resolveForUser($user);

        $this->assertCount(1, $tree, 'Only the operations parent should appear.');
        $this->assertEquals('operations', $tree[0]['key']);
        $this->assertCount(1, $tree[0]['children'], 'Only the granted child should appear.');
        $this->assertEquals('operations.dashboard', $tree[0]['children'][0]['key']);
    }

    public function test_user_grant_override_shows_hidden_menu(): void
    {
        $secret = $this->createMenu('secret', 'Secret', '/secret', null, 'Key', 0);
        $this->createMenu('dashboard', 'Dashboard', '/dashboard', null, 'LayoutDashboard', 10);

        $user = $this->createUserWithRole('accountant');

        UserMenuOverride::create([
            'user_id' => $user->id,
            'menu_item_id' => $secret->id,
            'granted' => true,
            'created_by' => $user->id,
        ]);

        $tree = $this->service->resolveForUser($user);

        $this->assertCount(1, $tree);
        $this->assertEquals('secret', $tree[0]['key']);
    }

    public function test_user_revoke_override_hides_visible_menu(): void
    {
        $public = $this->createMenu('public', 'Public', '/public', null, 'Globe', 0);
        $role = Role::create(['name' => 'tester', 'display_name' => 'Tester']);
        $user = $this->createUserWithRole('tester');

        RoleMenuVisibility::create([
            'role_id' => $role->id,
            'menu_item_id' => $public->id,
        ]);

        UserMenuOverride::create([
            'user_id' => $user->id,
            'menu_item_id' => $public->id,
            'granted' => false,
            'created_by' => $user->id,
        ]);

        $tree = $this->service->resolveForUser($user);

        $this->assertSame([], $tree, 'Revoke override should hide role-visible menu.');
    }

    public function test_empty_parent_groups_are_dropped(): void
    {
        $this->createMenu('operations', 'Operations', null, null, 'ShoppingCart', 0);
        $this->createMenu('operations.reconciliation', 'Reconciliation', '/operations/reconciliation', 'operations', null, 0);

        $role = Role::create(['name' => 'tester', 'display_name' => 'Tester']);
        $user = $this->createUserWithRole('tester');

        $parent = MenuItem::where('key', 'operations')->first();
        RoleMenuVisibility::create([
            'role_id' => $role->id,
            'menu_item_id' => $parent->id,
        ]);

        $tree = $this->service->resolveForUser($user);

        $this->assertSame([], $tree, 'Parent with no visible children should be dropped.');
    }

    public function test_get_matrix_returns_full_state(): void
    {
        $menu1 = $this->createMenu('dashboard', 'Dashboard', '/dashboard', null, 'LayoutDashboard', 0);
        $menu2 = $this->createMenu('settings', 'Settings', '/settings', null, 'Settings', 10);

        $user = $this->createUserWithRole('super_admin');
        $opsRole = Role::create(['name' => 'operations_manager', 'display_name' => 'Operations Manager']);
        $superRole = Role::where('name', 'super_admin')->first();

        RoleMenuVisibility::create([
            'role_id' => $superRole->id,
            'menu_item_id' => $menu1->id,
        ]);

        UserMenuOverride::create([
            'user_id' => $user->id,
            'menu_item_id' => $menu2->id,
            'granted' => false,
            'created_by' => $user->id,
        ]);

        $matrix = $this->service->getMatrix();

        $this->assertArrayHasKey('menus', $matrix);
        $this->assertArrayHasKey('roles', $matrix);
        $this->assertArrayHasKey('matrix', $matrix);
        $this->assertArrayHasKey('users_with_overrides', $matrix);

        $this->assertCount(2, $matrix['menus']);
        $this->assertCount(2, $matrix['roles']);

        $this->assertTrue($matrix['matrix']['dashboard']['super_admin']);
        $this->assertFalse($matrix['matrix']['dashboard']['operations_manager']);
        $this->assertFalse($matrix['matrix']['settings']['super_admin']);

        $this->assertCount(1, $matrix['users_with_overrides']);
        $this->assertEquals($user->id, $matrix['users_with_overrides'][0]['user_id']);
        $this->assertEquals(1, $matrix['users_with_overrides'][0]['override_count']);
        $this->assertEquals(['settings'], $matrix['users_with_overrides'][0]['menu_keys']);
    }

    public function test_set_role_visibility_toggles_and_flushes_cache(): void
    {
        $menu = $this->createMenu('toggleable', 'Toggleable', '/toggleable', null, 'ToggleLeft', 0);
        $role = Role::create(['name' => 'tester', 'display_name' => 'Tester']);
        $user = $this->createUserWithRole('tester');

        $treeBefore = $this->service->resolveForUser($user);
        $this->assertSame([], $treeBefore, 'No visibility initially.');

        $this->service->setRoleVisibility($role->id, $menu->id, true, $user->id);

        $treeAfterGrant = $this->service->resolveForUser($user);
        $this->assertCount(1, $treeAfterGrant);
        $this->assertEquals('toggleable', $treeAfterGrant[0]['key']);

        $this->service->setRoleVisibility($role->id, $menu->id, false, $user->id);

        $treeAfterRevoke = $this->service->resolveForUser($user);
        $this->assertSame([], $treeAfterRevoke, 'Revoked visibility should be reflected after cache flush.');
    }

    public function test_user_override_persists_and_can_be_removed(): void
    {
        $menu = $this->createMenu('override_target', 'Override Target', '/override', null, 'Target', 0);
        $user = $this->createUserWithRole('accountant');

        $this->service->setUserOverride($user->id, $menu->id, true, $user->id);

        $treeWithGrant = $this->service->resolveForUser($user);
        $this->assertCount(1, $treeWithGrant);
        $this->assertEquals('override_target', $treeWithGrant[0]['key']);

        $overrides = $this->service->getUserOverrides($user->id);
        $this->assertArrayHasKey('override_target', $overrides);
        $this->assertTrue($overrides['override_target']);

        $removed = $this->service->removeUserOverride($user->id, $menu->id, $user->id);
        $this->assertTrue($removed);

        $treeAfterRemove = $this->service->resolveForUser($user);
        $this->assertSame([], $treeAfterRemove, 'Removed override should revert to role default (hidden).');

        $overridesAfter = $this->service->getUserOverrides($user->id);
        $this->assertArrayNotHasKey('override_target', $overridesAfter);
    }

    private function dropAndRecreateSchema(): void
    {
        $this->dropSchema();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('display_name')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->string('company_logo')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('avatar')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('telegram_id')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->string('bot_verification_code')->nullable();
            $table->string('nickname')->nullable();
            $table->softDeletes();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['user_id', 'role_id']);
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('route')->nullable();
            $table->string('parent_key')->nullable();
            $table->string('icon_name')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index('parent_key');
        });

        Schema::create('role_menu_visibility', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->foreignId('menu_item_id')->constrained('menu_items')->onDelete('cascade');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['role_id', 'menu_item_id']);
        });

        Schema::create('user_menu_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('menu_item_id')->constrained('menu_items')->onDelete('cascade');
            $table->boolean('granted');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['user_id', 'menu_item_id']);
        });
    }

    private function dropSchema(): void
    {
        $tables = [
            'user_menu_overrides',
            'role_menu_visibility',
            'user_roles',
            'menu_items',
            'users',
            'roles',
        ];
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createMenu(string $key, string $label, ?string $route, ?string $parentKey, ?string $iconName, int $sortOrder): MenuItem
    {
        return MenuItem::create([
            'key' => $key,
            'label' => $label,
            'route' => $route,
            'parent_key' => $parentKey,
            'icon_name' => $iconName,
            'sort_order' => $sortOrder,
        ]);
    }

    private function createUserWithRole(string $roleSlug): User
    {
        $role = Role::where('slug', $roleSlug)->first();
        if (!$role) {
            $role = Role::create([
                'name' => $roleSlug,
                'display_name' => ucfirst(str_replace('_', ' ', $roleSlug)),
            ]);
        }
        return User::create([
            'name' => 'Test ' . ucfirst($roleSlug),
            'email' => $roleSlug . '_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    private function findNode(array $tree, string $key): ?array
    {
        foreach ($tree as $node) {
            if ($node['key'] === $key) {
                return $node;
            }
        }
        return null;
    }
}
