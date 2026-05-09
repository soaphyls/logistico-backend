<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\PartnerCustomer;
use Illuminate\Database\Seeder;

class FixPartnerCustomerPartnerIdSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Fixing partner_id for partner_customers...');

        $partners = User::whereHas('role', function ($q) {
            $q->where('slug', 'partner');
        })->get();

        if ($partners->isEmpty()) {
            $this->command->warn('No partners found. Creating default partner...');
            
            $partnerRole = \App\Models\Role::where('slug', 'partner')->first();
            if (!$partnerRole) {
                $this->command->error('Partner role not found.');
                return;
            }

            $partner = User::create([
                'name' => 'Jumia Nigeria',
                'email' => 'jumia@test.com',
                'password' => bcrypt('password'),
                'role_id' => $partnerRole->id,
                'company' => 'Jumia Nigeria',
                'is_active' => true,
            ]);
            $partners->push($partner);
        }

        $partnerCustomers = PartnerCustomer::whereNull('partner_id')->get();
        
        if ($partnerCustomers->isEmpty()) {
            $this->command->info('All partner_customers already have partner_id set.');
            return;
        }

        $this->command->info("Updating {$partnerCustomers->count()} partner_customers...");

        foreach ($partners as $index => $partner) {
            $this->command->info("Partner: {$partner->company} (ID: {$partner->id})");
        }

        $partnerCustomers->each(function ($pc, $index) use ($partners) {
            $partner = $partners->get($index % $partners->count());
            $pc->update(['partner_id' => $partner->id]);
            $this->command->line("  - PartnerCustomer #{$pc->id} -> Partner #{$partner->id} ({$partner->company})");
        });

        $this->command->info('Done!');
    }
}
