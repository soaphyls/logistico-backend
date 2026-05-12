<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, SoftDeletes;

    protected $fillable = [
        'name',
        'nickname',
        'email',
        'password',
        'phone',
        'company',
        'company_logo',
        'bank_name',
        'bank_account_name',
        'bank_account_number',
        'role_id',
        'assigned_staff_id',
        'is_active',
        'avatar',
        'last_login_at',
        'telegram_id',
        'whatsapp_number',
        'bot_verification_code',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::created(function (User $user) {
            $role = Role::find($user->role_id);
            if ($role && $role->slug === 'dispatcher') {
                Dispatcher::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'license_number' => 'DL-' . strtoupper(uniqid()),
                        'license_expiry' => now()->addYear(),
                        'is_available' => true,
                    ]
                );
            }
        });

        static::updated(function (User $user) {
            if ($user->isDirty('role_id')) {
                $newRole = $user->role_id;
                
                $role = Role::find($newRole);
                
                if ($role && $role->slug === 'dispatcher' && !$user->dispatcher) {
                    Dispatcher::create([
                        'user_id' => $user->id,
                        'license_number' => 'DL-' . strtoupper(uniqid()),
                        'license_expiry' => now()->addYear(),
                        'is_available' => true,
                    ]);
                }
            }
        });
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_staff_id');
    }

    public function dispatcher(): HasOne
    {
        return $this->hasOne(Dispatcher::class);
    }

    public function createdShipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'created_by');
    }

    public function assignedShipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'assigned_by');
    }

    public function tasksAssignedTo(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function tasksAssignedBy(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_by');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function managedWarehouse(): HasOne
    {
        return $this->hasOne(Warehouse::class, 'manager_id');
    }

    public function partnerCustomers(): HasMany
    {
        return $this->hasMany(PartnerCustomer::class, 'partner_id');
    }

    public function partnerProducts(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(
            PartnerProduct::class,
            PartnerCustomer::class,
            'partner_id',
            'partner_customer_id',
            'id',
            'id'
        );
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isManager(): bool
    {
        return $this->hasRole('operations_manager');
    }

    public function isDispatcher(): bool
    {
        return $this->hasRole('dispatcher');
    }

    public function isAccountant(): bool
    {
        return $this->hasRole('accountant');
    }

    public function isWarehouseOfficer(): bool
    {
        return $this->hasRole('warehouse_officer');
    }

    public function isCustomerService(): bool
    {
        return $this->hasRole('customer_service');
    }

    public function hasRole(string $role): bool
    {
        // Check single role relationship (role_id column)
        if ($this->role && ($this->role->slug === $role || $this->role->slug === str_replace('_', '-', $role))) {
            return true;
        }

        // Check many-to-many relationship (user_roles table)
        return $this->roles()->where(function($q) use ($role) {
            $q->where('slug', $role)->orWhere('slug', str_replace('_', '-', $role));
        })->exists();
    }

    public function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }
        return false;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function botSessions(): HasMany
    {
        return $this->hasMany(BotSession::class);
    }

    public function permissionOverrides()
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    public function hasPermission(string $permissionSlug): bool
    {
        return app(PermissionService::class)->hasPermission($this->id, $permissionSlug);
    }

    public function getAllPermissions(): array
    {
        return app(PermissionService::class)->getUserPermissions($this->id);
    }

    public function hasOverride(string $permissionSlug): bool
    {
        return $this->permissionOverrides()
            ->whereHas('permission', function ($query) use ($permissionSlug) {
                $query->where('slug', $permissionSlug);
            })
            ->exists();
    }

    public function getOverrideType(string $permissionSlug): ?string
    {
        $override = $this->permissionOverrides()
            ->whereHas('permission', function ($query) use ($permissionSlug) {
                $query->where('slug', $permissionSlug);
            })
            ->first();
        
        return $override?->override_type;
    }

    /**
     * Accessor for company_logo to return absolute URL
     */
    public function getCompanyLogoAttribute($value)
    {
        if (!$value) return null;
        if (filter_var($value, FILTER_VALIDATE_URL)) return $value;
        return asset($value);
    }

    /**
     * Accessor for avatar to return absolute URL
     */
    public function getAvatarAttribute($value)
    {
        if (!$value) return null;
        if (filter_var($value, FILTER_VALIDATE_URL)) return $value;
        return asset($value);
    }
}
