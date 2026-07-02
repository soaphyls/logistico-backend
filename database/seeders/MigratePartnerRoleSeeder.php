<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MigratePartnerRoleSeeder extends Seeder
{
    public function run(): void
    {
        $partnerRole = Role::where('name', 'partner')->first();
        $partnerStaffRole = Role::where('name', 'partner_staff')->first();

        if (!$partnerRole) {
            $this->command->error('Partner role is missing. Run RoleSeeder first.');
            return;
        }

        if (!$partnerStaffRole) {
            $this->command->warn('partner_staff role is missing. Nothing to migrate.');
            return;
        }

        $reassigned = 0;

        User::where('role_id', $partnerStaffRole->id)
            ->where(function ($q) use ($partnerStaffRole) {
                $q->whereNull('parent_id')
                  ->orWhereColumn('parent_id', 'id');
            })
            ->chunkById(100, function ($users) use ($partnerRole, &$reassigned) {
                foreach ($users as $user) {
                    DB::transaction(function () use ($user, $partnerRole, &$reassigned) {
                        $user->role_id = $partnerRole->id;
                        $user->parent_id = null;
                        $user->save();
                        $reassigned++;
                    });
                }
            });

        $this->command->info("Migrated {$reassigned} mis-assigned partner user(s) from partner_staff to partner.");
    }
}
