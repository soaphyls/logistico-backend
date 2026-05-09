<?php

namespace App\Console\Commands;

use App\Models\Dispatcher;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

class FixDispatcherRecords extends Command
{
    protected $signature = 'app:fix-dispatcher-records';
    protected $description = 'Create dispatcher records for users with dispatcher role but no dispatcher profile';

    public function handle()
    {
        $dispatcherRole = Role::where('name', 'dispatcher')->first();
        
        if (!$dispatcherRole) {
            $this->error('Dispatcher role not found!');
            return 1;
        }

        $usersWithDispatcherRole = User::where('role_id', $dispatcherRole->id)
            ->whereDoesntHave('dispatcher')
            ->get();

        if ($usersWithDispatcherRole->isEmpty()) {
            $this->info('All users with dispatcher role already have dispatcher records!');
            return 0;
        }

        $this->info('Found ' . $usersWithDispatcherRole->count() . ' users needing dispatcher records...');

        foreach ($usersWithDispatcherRole as $user) {
            Dispatcher::create([
                'user_id' => $user->id,
                'license_number' => 'DL-' . strtoupper(uniqid()),
                'license_expiry' => now()->addYear(),
                'is_available' => true,
            ]);
            $this->line('Created dispatcher record for: ' . $user->name);
        }

        $this->info('Done! Created ' . $usersWithDispatcherRole->count() . ' dispatcher records.');
        return 0;
    }
}
