<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Salary;
use App\Models\User;
use App\Models\Expense;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalaryController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search');
        $status = $request->get('status');
        $month = $request->get('month');
        $year = $request->get('year');
        $role = $request->get('role');

        $query = Salary::with(['user', 'user.roles', 'recordedBy']);

        if ($search) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($month) {
            $query->where('month', $month);
        }

        if ($year) {
            $query->where('year', $year);
        }

        if ($role) {
            $query->whereHas('user.roles', function ($q) use ($role) {
                $q->where('slug', $role);
            });
        }

        $salaries = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return $this->success($salaries);
    }

    public function show(Salary $salary)
    {
        $salary->load(['user', 'user.roles', 'recordedBy']);
        return $this->success($salary);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'base_salary' => 'required|numeric|min:0',
            'allowances' => 'nullable|numeric|min:0',
            'deductions' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string',
            'payment_reference' => 'nullable|string',
            'payment_date' => 'nullable|date',
            'month' => 'required|string',
            'year' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        $validated['net_salary'] = $validated['base_salary'] + ($validated['allowances'] ?? 0) - ($validated['deductions'] ?? 0);
        $validated['recorded_by'] = auth()->id();

        if (!empty($validated['payment_date'])) {
            $validated['status'] = 'paid';
        } else {
            $validated['status'] = 'pending';
        }

        $salary = Salary::create($validated);
        
        $userName = $salary->user->name ?? 'Unknown';

        if ($validated['status'] === 'paid') {
            Expense::create([
                'category' => 'Salary',
                'title' => 'Salary Payment - ' . $userName . ' (' . $validated['month'] . ' ' . $validated['year'] . ')',
                'amount' => $validated['net_salary'],
                'expense_date' => $validated['payment_date'],
                'dispatcher_id' => null,
                'notes' => 'Salary payment for ' . $validated['month'] . ' ' . $validated['year'],
                'recorded_by' => auth()->id(),
            ]);
        }

        $salary->load(['user', 'user.roles', 'recordedBy']);
        return $this->success($salary, 'Salary record created successfully');
    }

    public function update(Request $request, Salary $salary)
    {
        $validated = $request->validate([
            'user_id' => 'sometimes|exists:users,id',
            'base_salary' => 'sometimes|numeric|min:0',
            'allowances' => 'nullable|numeric|min:0',
            'deductions' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string',
            'payment_reference' => 'nullable|string',
            'payment_date' => 'nullable|date',
            'month' => 'sometimes|string',
            'year' => 'sometimes|string',
            'status' => 'sometimes|in:pending,paid,draft',
            'notes' => 'nullable|string',
        ]);

        $salary->update($validated);

        if (isset($validated['base_salary']) || isset($validated['allowances']) || isset($validated['deductions'])) {
            $salary->net_salary = $salary->base_salary + $salary->allowances - $salary->deductions;
            $salary->save();
        }

        $salary->load(['user', 'user.roles', 'recordedBy']);
        return $this->success($salary, 'Salary record updated successfully');
    }

    public function destroy(Salary $salary)
    {
        $salary->delete();
        return $this->success(null, 'Salary record deleted successfully');
    }

    public function markAsPaid(Request $request, Salary $salary)
    {
        $validated = $request->validate([
            'payment_method' => 'required|string',
            'payment_reference' => 'nullable|string',
            'payment_date' => 'required|date',
        ]);

        $salary->update([
            'status' => 'paid',
            'payment_method' => $validated['payment_method'],
            'payment_reference' => $validated['payment_reference'],
            'payment_date' => $validated['payment_date'],
        ]);

        Expense::create([
            'category' => 'Salary',
            'title' => 'Salary Payment - ' . $salary->user->name . ' (' . $salary->month . ' ' . $salary->year . ')',
            'amount' => $salary->net_salary,
            'expense_date' => $validated['payment_date'],
            'dispatcher_id' => null,
            'notes' => 'Salary payment for ' . $salary->month . ' ' . $salary->year,
            'recorded_by' => auth()->id(),
        ]);

        $salary->load(['user', 'user.roles', 'recordedBy']);
        return $this->success($salary, 'Salary marked as paid');
    }

    public function employees(Request $request)
    {
        $role = $request->get('role');
        
        $roleIds = [2, 3, 4, 5, 6];
        
        $query = User::whereIn('role_id', $roleIds);

        if ($role) {
            $roleId = Role::where('slug', $role)->first()?->id;
            if ($roleId) {
                $query->where('role_id', $roleId);
            }
        }

        $employees = $query->get(['id', 'name', 'email']);
        return $this->success($employees);
    }

    public function dashboard()
    {
        $currentMonth = date('m');
        $currentYear = date('Y');

        $stats = [
            'total_payroll' => Salary::where('month', $currentMonth)
                ->where('year', $currentYear)
                ->sum('net_salary'),
            'paid_amount' => Salary::where('month', $currentMonth)
                ->where('year', $currentYear)
                ->where('status', 'paid')
                ->sum('net_salary'),
            'pending_amount' => Salary::where('month', $currentMonth)
                ->where('year', $currentYear)
                ->where('status', 'pending')
                ->sum('net_salary'),
            'total_employees' => Salary::where('month', $currentMonth)
                ->where('year', $currentYear)
                ->count('user_id'),
            'paid_count' => Salary::where('month', $currentMonth)
                ->where('year', $currentYear)
                ->where('status', 'paid')
                ->count('user_id'),
            'pending_count' => Salary::where('month', $currentMonth)
                ->where('year', $currentYear)
                ->where('status', 'pending')
                ->count('user_id'),
        ];

        $stats['average_salary'] = $stats['total_employees'] > 0 
            ? $stats['total_payroll'] / $stats['total_employees'] 
            : 0;

        $monthlyTrend = Salary::select(
            DB::raw('SUM(net_salary) as total'),
            DB::raw('CONCAT(year, "-", month) as month')
        )
            ->whereRaw("CONCAT(year, '-', LPAD(month, 2, '0')) >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 6 MONTH), '%Y-%m')")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return $this->success([
            'stats' => $stats,
            'monthly_trend' => $monthlyTrend,
        ]);
    }

    public function bulkPay(Request $request)
    {
        $validated = $request->validate([
            'salary_ids' => 'required|array',
            'salary_ids.*' => 'exists:salaries,id',
            'payment_method' => 'required|string',
            'payment_reference' => 'nullable|string',
            'payment_date' => 'required|date',
        ]);

        $salaries = Salary::whereIn('id', $validated['salary_ids'])
            ->where('status', 'pending')
            ->get();

        $totalAmount = 0;

        foreach ($salaries as $salary) {
            $salary->update([
                'status' => 'paid',
                'payment_method' => $validated['payment_method'],
                'payment_reference' => $validated['payment_reference'],
                'payment_date' => $validated['payment_date'],
            ]);

            Expense::create([
                'category' => 'Salary',
                'title' => 'Salary Payment - ' . $salary->user->name . ' (' . $salary->month . ' ' . $salary->year . ')',
                'amount' => $salary->net_salary,
                'expense_date' => $validated['payment_date'],
                'dispatcher_id' => null,
                'notes' => 'Salary payment for ' . $salary->month . ' ' . $salary->year,
                'recorded_by' => auth()->id(),
            ]);

            $totalAmount += $salary->net_salary;
        }

        return $this->success([
            'processed' => $salaries->count(),
            'total_amount' => $totalAmount,
        ], 'Bulk payment processed successfully');
    }
}
