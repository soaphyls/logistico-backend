<?php

namespace Database\Seeders;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;

class TaskSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@logistico.com')->first();
        $manager = User::where('email', 'manager@logistico.com')->first();
        $warehouse = User::where('email', 'warehouse@logistico.com')->first();
        
        $tasks = [
            ['title' => 'Review pending shipments', 'description' => 'Check all pending shipments and process for dispatch', 'assigned_to' => $manager->id, 'assigned_by' => $admin->id, 'priority' => 'high', 'status' => 'in_progress', 'due_date' => now()->addDays(2)],
            ['title' => 'Update inventory counts', 'description' => 'Conduct monthly inventory count for warehouse WH-001', 'assigned_to' => $warehouse->id, 'assigned_by' => $manager->id, 'priority' => 'medium', 'status' => 'pending', 'due_date' => now()->addDays(5)],
            ['title' => 'Vehicle maintenance check', 'description' => 'Schedule preventive maintenance for LAG-003', 'assigned_to' => $manager->id, 'assigned_by' => $admin->id, 'priority' => 'urgent', 'status' => 'completed', 'due_date' => now()->subDays(1), 'completed_at' => now()->subDays(1)],
            ['title' => 'Dispatcher performance review', 'description' => 'Review delivery success rates for Q1', 'assigned_to' => $admin->id, 'assigned_by' => $admin->id, 'priority' => 'low', 'status' => 'pending', 'due_date' => now()->addDays(10)],
            ['title' => 'Process pending invoices', 'description' => 'Send all drafted invoices to customers', 'assigned_to' => $manager->id, 'assigned_by' => $admin->id, 'priority' => 'high', 'status' => 'pending', 'due_date' => now()->addDays(1)],
            ['title' => 'Warehouse cleaning', 'description' => 'Schedule deep cleaning for warehouse facility', 'assigned_to' => $warehouse->id, 'assigned_by' => $manager->id, 'priority' => 'low', 'status' => 'completed', 'due_date' => now()->subDays(3), 'completed_at' => now()->subDays(3)],
        ];

        foreach ($tasks as $taskData) {
            Task::create($taskData);
        }
    }
}
