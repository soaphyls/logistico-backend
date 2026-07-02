<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\PermissionAuditLog;
use App\Models\Role;
use App\Models\RoleMenuVisibility;
use App\Models\User;
use App\Models\UserMenuOverride;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MenuVisibilityControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->dropAndRecreateSchema();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
        parent::tearDown();
    }

    public function test_guest_cannot_access_menu_endpoints(): void
    {
        $this->getJson('/api/v1/menu/permissions')->assertStatus(401);
        $this->getJson('/api/v1/menu/me')->assertStatus(401);
        $this->getJson('/api/v1/menu/guard?route=/dashboard')->assertStatus(401);
    }

    public function test_super_admin_can_get_matrix(): void
    {
        $this->createMenu('dashboard', 'Dashboard', '/dashboard', null, 'LayoutDashboard', 0);
        $this->createMenu('settings', 'Settings', '/settings', null, 'Settings', 10);

        $admin = $this->createUserWithRole('super_admin');

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/menu/permissions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'menus',
                    'roles',
                    'matrix' => [
                        'dashboard',
                        'settings',
                    ],
                    'users_with_overrides',
                ],
            ]);
    }

    public function test_non_admin_cannot_get_matrix(): void
    {
        $this->createMenu('dashboard', 'Dashboard', '/dashboard', null, null, 0);
        $manager = $this->createUserWithRole('operations_manager');

        $response = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/menu/permissions');

        $response->assertStatus(403);
    }

    public function test_any_user_can_get_their_own_menu(): void
    {
        $dashboard = $this->createMenu('dashboard', 'Dashboard', '/dashboard', null, null, 0);
        $manager = $this->createUserWithRole('operations_manager');
        $role = Role::where('slug', 'operations_manager')->first();

        RoleMenuVisibility::create([
            'role_id' => $role->id,
            'menu_item_id' => $dashboard->id,
        ]);

        $response = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/menu/me');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    ['key' => 'dashboard', 'label' => 'Dashboard', 'route' => '/dashboard'],
                ],
            ]);
    }

    public function test_super_admin_can_toggle_role_visibility(): void
    {
        $menu = $this->createMenu('reports', 'Reports', '/reports', null, null, 0);
        $admin = $this->createUserWithRole('super_admin');
        $targetRole = Role::create(['name' => 'analyst', 'slug' => 'analyst', 'display_name' => 'Analyst']);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/menu/role/{$targetRole->id}/{$menu->id}", ['visible' => true]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'role_id' => $targetRole->id,
                    'menu_id' => $menu->id,
                    'menu_key' => 'reports',
                    'visible' => true,
                ],
            ]);

        $this->assertDatabaseHas('role_menu_visibility', [
            'role_id' => $targetRole->id,
            'menu_item_id' => $menu->id,
        ]);
    }

    public function test_super_admin_can_grant_user_override(): void
    {
        $menu = $this->createMenu('reports', 'Reports', '/reports', null, null, 0);
        $admin = $this->createUserWithRole('super_admin');
        $target = $this->createUserWithRole('operations_manager');

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/menu/user/{$target->id}/{$menu->id}", ['granted' => true]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user_id' => $target->id,
                    'menu_id' => $menu->id,
                    'menu_key' => 'reports',
                    'granted' => true,
                ],
            ]);

        $this->assertDatabaseHas('user_menu_overrides', [
            'user_id' => $target->id,
            'menu_item_id' => $menu->id,
            'granted' => 1,
        ]);
    }

    public function test_admin_cannot_override_their_own_menus(): void
    {
        $menu = $this->createMenu('reports', 'Reports', '/reports', null, null, 0);
        $admin = $this->createUserWithRole('super_admin');

        $putResponse = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/menu/user/{$admin->id}/{$menu->id}", ['granted' => true]);
        $putResponse->assertStatus(403);

        $deleteResponse = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/menu/user/{$admin->id}/{$menu->id}");
        $deleteResponse->assertStatus(403);
    }

    public function test_audit_log_written_on_role_toggle(): void
    {
        $menu = $this->createMenu('reports', 'Reports', '/reports', null, null, 0);
        $admin = $this->createUserWithRole('super_admin');
        $targetRole = Role::create(['name' => 'analyst', 'slug' => 'analyst', 'display_name' => 'Analyst']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/menu/role/{$targetRole->id}/{$menu->id}", ['visible' => true])
            ->assertStatus(200);

        $log = PermissionAuditLog::where('action', 'role_menu_visible')->first();
        $this->assertNotNull($log);
        $this->assertEquals('reports', $log->permission);
        $this->assertEquals('role', $log->subject_type);
        $this->assertEquals($targetRole->id, $log->subject_id);
        $this->assertNull($log->user_id);
        $this->assertEquals($admin->id, $log->changed_by);
    }

    public function test_audit_log_written_on_user_override(): void
    {
        $menu = $this->createMenu('reports', 'Reports', '/reports', null, null, 0);
        $admin = $this->createUserWithRole('super_admin');
        $target = $this->createUserWithRole('operations_manager');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/menu/user/{$target->id}/{$menu->id}", ['granted' => true])
            ->assertStatus(200);

        $log = PermissionAuditLog::where('action', 'user_menu_granted')->first();
        $this->assertNotNull($log);
        $this->assertEquals('reports', $log->permission);
        $this->assertEquals('user', $log->subject_type);
        $this->assertEquals($target->id, $log->subject_id);
        $this->assertEquals($target->id, $log->user_id);
        $this->assertEquals($admin->id, $log->changed_by);
    }

    public function test_can_access_guard_returns_correct_bool(): void
    {
        $dashboard = $this->createMenu('dashboard', 'Dashboard', '/dashboard', null, null, 0);
        $admin = $this->createUserWithRole('super_admin');

        $adminResponse = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/menu/guard?route=/dashboard');
        $adminResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => ['route' => '/dashboard', 'can_access' => true],
            ]);

        $manager = $this->createUserWithRole('operations_manager');
        $managerRole = Role::where('slug', 'operations_manager')->first();

        $missing = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/menu/guard?route=/dashboard');
        $missing->assertStatus(200)
            ->assertJson(['data' => ['can_access' => false]]);

        RoleMenuVisibility::create([
            'role_id' => $managerRole->id,
            'menu_item_id' => $dashboard->id,
        ]);

        app(\App\Services\MenuVisibilityService::class)->flushCache($manager->id);

        $present = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/menu/guard?route=/dashboard');
        $present->assertStatus(200)
            ->assertJson(['data' => ['can_access' => true]]);
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

        Schema::create('permission_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('changed_by')->constrained('users')->onDelete('cascade');
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('permission')->nullable();
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    private function dropSchema(): void
    {
        $tables = [
            'personal_access_tokens',
            'permission_audit_logs',
            'user_menu_overrides',
            'role_menu_visibility',
            'menu_items',
            'user_roles',
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
                'slug' => $roleSlug,
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
}
