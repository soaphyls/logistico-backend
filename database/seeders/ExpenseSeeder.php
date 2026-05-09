<?php

namespace Database\Seeders;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Seeder;

class ExpenseSeeder extends Seeder
{
    public function run(): void
    {
        $accountant = User::where('email', 'accountant@logistico.com')->first();
        
        $expenses = [
            ['category' => 'fuel', 'title' => 'Fuel - Week 1', 'amount' => 15000, 'expense_date' => now()->subDays(7), 'vehicle_id' => 1],
            ['category' => 'fuel', 'title' => 'Fuel - Week 1', 'amount' => 25000, 'expense_date' => now()->subDays(7), 'vehicle_id' => 2],
            ['category' => 'fuel', 'title' => 'Fuel - Week 2', 'amount' => 18000, 'expense_date' => now()->subDays(1), 'vehicle_id' => 1],
            ['category' => 'maintenance', 'title' => 'Vehicle Service', 'amount' => 35000, 'expense_date' => now()->subDays(10), 'vehicle_id' => 3],
            ['category' => 'salary', 'title' => 'Dispatcher Salary - January', 'amount' => 150000, 'expense_date' => now()->subDays(15), 'dispatcher_id' => 1],
            ['category' => 'salary', 'title' => 'Dispatcher Salary - January', 'amount' => 150000, 'expense_date' => now()->subDays(15), 'dispatcher_id' => 2],
            ['category' => 'warehouse_rent', 'title' => 'Warehouse Rent - January', 'amount' => 250000, 'expense_date' => now()->subDays(5)],
            ['category' => 'utilities', 'title' => 'Electricity Bill', 'amount' => 45000, 'expense_date' => now()->subDays(3)],
            ['category' => 'office', 'title' => 'Office Supplies', 'amount' => 12000, 'expense_date' => now()->subDays(8)],
            ['category' => 'fuel', 'title' => 'Fuel - Week 2', 'amount' => 22000, 'expense_date' => now()->subDays(1), 'vehicle_id' => 2],
            ['category' => 'fuel', 'title' => 'Fuel - Week 3', 'amount' => 20000, 'expense_date' => now(), 'vehicle_id' => 4],
            ['category' => 'maintenance', 'title' => 'Tire Replacement', 'amount' => 80000, 'expense_date' => now()->subDays(20), 'vehicle_id' => 3],
            ['category' => 'other', 'title' => 'Miscellaneous', 'amount' => 5000, 'expense_date' => now()->subDays(12)],
            ['category' => 'utilities', 'title' => 'Water Bill', 'amount' => 15000, 'expense_date' => now()->subDays(3)],
            ['category' => 'office', 'title' => 'Internet Service', 'amount' => 25000, 'expense_date' => now()->subDays(2)],
        ];

        foreach ($expenses as $expenseData) {
            $expenseData['recorded_by'] = $accountant->id;
            Expense::create($expenseData);
        }
    }
}
