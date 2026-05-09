<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $query = Expense::with(['vehicle', 'dispatcher', 'recordedBy']);

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        if ($request->has('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }

        if ($request->has('dispatcher_id')) {
            $query->where('dispatcher_id', $request->dispatcher_id);
        }

        if ($request->has('date_from')) {
            $query->whereDate('expense_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('expense_date', '<=', $request->date_to);
        }

        $expenses = $query->orderBy('expense_date', 'desc')->paginate(20);

        return $this->success($expenses);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category' => 'required|in:fuel,maintenance,salary,warehouse_rent,utilities,office,other',
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'dispatcher_id' => 'nullable|exists:dispatchers,id',
            'receipt_path' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $validated['recorded_by'] = auth()->id();

        $expense = Expense::create($validated);

        return $this->success($expense, 'Expense created successfully', 201);
    }

    public function show(Expense $expense)
    {
        $expense->load(['vehicle', 'dispatcher', 'recordedBy']);

        return $this->success($expense);
    }

    public function update(Request $request, Expense $expense)
    {
        $validated = $request->validate([
            'category' => 'sometimes|in:fuel,maintenance,salary,warehouse_rent,utilities,office,other',
            'title' => 'sometimes|string|max:255',
            'amount' => 'sometimes|numeric|min:0.01',
            'expense_date' => 'sometimes|date',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'dispatcher_id' => 'nullable|exists:dispatchers,id',
            'receipt_path' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $expense->update($validated);

        return $this->success($expense, 'Expense updated successfully');
    }

    public function destroy(Expense $expense)
    {
        $expense->delete();

        return $this->success(null, 'Expense deleted successfully');
    }

    public function summary(Request $request)
    {
        $query = Expense::query();

        if ($request->has('year')) {
            $query->whereYear('expense_date', $request->year);
        } else {
            $query->whereYear('expense_date', now()->year);
        }

        $byCategory = $query->select('category')
            ->selectRaw('SUM(amount) as total')
            ->groupBy('category')
            ->pluck('total', 'category')
            ->toArray();

        $total = array_sum($byCategory);

        return $this->success([
            'by_category' => $byCategory,
            'total' => $total,
        ]);
    }
}
