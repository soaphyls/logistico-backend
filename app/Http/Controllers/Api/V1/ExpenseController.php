<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'category' => 'required|string|max:255',
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
            'category' => 'sometimes|string|max:255',
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

    public function storeDailyLog(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'fueling.riders' => 'array',
            'fueling.topUps' => 'array',
            'staff_support' => 'array',
            'maintenance' => 'array',
            'office_maintenance' => 'array',
            'packaging' => 'array',
            'custom' => 'array',
        ]);

        $date = $validated['date'];
        $recordedBy = auth()->id();
        $expenses = [];

        DB::beginTransaction();
        try {
            // Delete existing expenses for this date to avoid duplication upon edit/resubmit
            Expense::where('expense_date', $date)->delete();

            // Process Fueling Riders
            if (!empty($validated['fueling']['riders'])) {
                foreach ($validated['fueling']['riders'] as $item) {
                    if (empty($item['amount'])) continue;
                    $expenses[] = [
                        'category' => 'fueling',
                        'title' => 'Rider Fueling - ' . ($item['rider_name'] ?? 'Unknown'),
                        'amount' => $item['amount'],
                        'expense_date' => $date,
                        'dispatcher_id' => $item['rider_id'] ?? null,
                        'notes' => ($item['litres'] ?? '') . ' Litres',
                        'recorded_by' => $recordedBy,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            // Process Fueling TopUps
            if (!empty($validated['fueling']['topUps'])) {
                foreach ($validated['fueling']['topUps'] as $item) {
                    if (empty($item['amount'])) continue;
                    $expenses[] = [
                        'category' => 'fueling',
                        'title' => 'Fuel TopUp - ' . ($item['rider_name'] ?? 'Unknown'),
                        'amount' => $item['amount'],
                        'expense_date' => $date,
                        'dispatcher_id' => $item['rider_id'] ?? null,
                        'notes' => ($item['litres'] ?? '') . ' Litres. Reason: ' . ($item['reason'] ?? ''),
                        'recorded_by' => $recordedBy,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            // Process Staff Support
            if (!empty($validated['staff_support'])) {
                foreach ($validated['staff_support'] as $item) {
                    if (empty($item['amount_given'])) continue;
                    $expenses[] = [
                        'category' => 'staff_support',
                        'title' => 'Staff Support - ' . ($item['staff_name'] ?? 'Unknown'),
                        'amount' => $item['amount_given'],
                        'expense_date' => $date,
                        'notes' => 'Dest: ' . ($item['client_destination'] ?? '') . ' | Items: ' . ($item['items_description'] ?? ''),
                        'recorded_by' => $recordedBy,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            // Process Maintenance
            if (!empty($validated['maintenance'])) {
                foreach ($validated['maintenance'] as $item) {
                    if (empty($item['amount_spent'])) continue;
                    $expenses[] = [
                        'category' => 'maintenance',
                        'title' => 'Maintenance - ' . ($item['plate_number'] ?? 'Unknown'),
                        'amount' => $item['amount_spent'],
                        'expense_date' => $date,
                        'vehicle_id' => $item['vehicle_id'] ?? null,
                        'notes' => $item['comment'] ?? '',
                        'recorded_by' => $recordedBy,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            // Process Office Maintenance
            if (!empty($validated['office_maintenance'])) {
                foreach ($validated['office_maintenance'] as $item) {
                    if (empty($item['amount'])) continue;
                    $expenses[] = [
                        'category' => 'office',
                        'title' => 'Office - ' . ($item['item_type'] ?? 'Item'),
                        'amount' => $item['amount'],
                        'expense_date' => $date,
                        'notes' => $item['comment'] ?? '',
                        'recorded_by' => $recordedBy,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            // Process Packaging
            if (!empty($validated['packaging'])) {
                foreach ($validated['packaging'] as $item) {
                    if (empty($item['amount'])) continue;
                    $expenses[] = [
                        'category' => 'packaging',
                        'title' => 'Packaging - ' . ($item['item_name'] ?? 'Item'),
                        'amount' => $item['amount'],
                        'expense_date' => $date,
                        'notes' => 'Qty: ' . ($item['quantity'] ?? '1') . ' | ' . ($item['comment'] ?? ''),
                        'recorded_by' => $recordedBy,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            // Process Custom Categories
            if (!empty($validated['custom'])) {
                foreach ($validated['custom'] as $customCategory => $items) {
                    foreach ($items as $item) {
                        if (empty($item['amount'])) continue;
                        $expenses[] = [
                            'category' => strtolower(str_replace(' ', '_', $customCategory)),
                            'title' => 'Custom - ' . ($item['item_name'] ?? $item['title'] ?? 'Item'),
                            'amount' => $item['amount'],
                            'expense_date' => $date,
                            'notes' => $item['comment'] ?? '',
                            'recorded_by' => $recordedBy,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
            }

            if (!empty($expenses)) {
                $formattedExpenses = array_map(function ($expense) use ($date, $recordedBy) {
                    return array_merge([
                        'category' => null,
                        'title' => null,
                        'amount' => 0,
                        'expense_date' => $date,
                        'dispatcher_id' => null,
                        'vehicle_id' => null,
                        'notes' => null,
                        'recorded_by' => $recordedBy,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ], $expense);
                }, $expenses);

                Expense::insert($formattedExpenses);
            }

            DB::commit();
            return $this->success(null, 'Daily log submitted successfully', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to submit daily log: ' . $e->getMessage(), 500);
        }
    }

    public function getDailyLog(Request $request)
    {
        $request->validate([
            'date' => 'required|date_format:Y-m-d'
        ]);

        $date = $request->date;

        // Fetch all expenses for this date with vehicle and dispatcher details
        $expenses = Expense::where('expense_date', $date)
            ->with(['vehicle', 'dispatcher'])
            ->get();

        // If no expenses exist, return a clean initialized state
        if ($expenses->isEmpty()) {
            return $this->success([
                'date' => $date,
                'fueling' => [
                    'riders' => [],
                    'topUps' => []
                ],
                'staff_support' => [],
                'maintenance' => [],
                'office_maintenance' => [],
                'packaging' => [],
                'custom' => new \stdClass()
            ]);
        }

        $fuelingRiders = [];
        $fuelingTopUps = [];
        $staffSupport = [];
        $maintenance = [];
        $officeMaintenance = [];
        $packaging = [];
        $custom = [];

        foreach ($expenses as $exp) {
            $category = $exp->category;
            $notes = $exp->notes ?? '';

            if ($category === 'fueling') {
                $isTopUp = str_starts_with($exp->title, 'Fuel TopUp');
                $litres = '';
                $reason = '';

                if (preg_match('/^([\d.]+)\s+Litres/i', $notes, $matches)) {
                    $litres = $matches[1];
                }
                if ($isTopUp && preg_match('/Reason:\s*(.*)$/i', $notes, $matches)) {
                    $reason = $matches[1];
                }

                $item = [
                    'rider_name' => $exp->dispatcher ? $exp->dispatcher->name : str_replace(['Rider Fueling - ', 'Fuel TopUp - '], '', $exp->title),
                    'rider_id' => $exp->dispatcher_id ? (string)$exp->dispatcher_id : '',
                    'plate_number' => $exp->dispatcher && $exp->dispatcher->vehicle ? $exp->dispatcher->vehicle->plate_number : ($exp->vehicle ? $exp->vehicle->plate_number : ''),
                    'litres' => $litres,
                    'amount' => (string)$exp->amount,
                ];

                if ($isTopUp) {
                    $item['reason'] = $reason;
                    $fuelingTopUps[] = $item;
                } else {
                    $fuelingRiders[] = $item;
                }
            } elseif ($category === 'staff_support') {
                $dest = '';
                $itemsDesc = '';
                if (preg_match('/Dest:\s*(.*?)\s*\|\s*Items:\s*(.*)$/i', $notes, $matches)) {
                    $dest = $matches[1];
                    $itemsDesc = $matches[2];
                }

                $staffSupport[] = [
                    'staff_name' => str_replace('Staff Support - ', '', $exp->title),
                    'client_destination' => $dest,
                    'items_description' => $itemsDesc,
                    'amount_given' => (string)$exp->amount,
                ];
            } elseif ($category === 'maintenance') {
                $maintenance[] = [
                    'vehicle_id' => $exp->vehicle_id ? (string)$exp->vehicle_id : '',
                    'plate_number' => $exp->vehicle ? $exp->vehicle->plate_number : str_replace('Maintenance - ', '', $exp->title),
                    'comment' => $notes,
                    'amount_spent' => (string)$exp->amount,
                ];
            } elseif ($category === 'office') {
                $officeMaintenance[] = [
                    'item_type' => str_replace('Office - ', '', $exp->title),
                    'amount' => (string)$exp->amount,
                    'comment' => $notes,
                ];
            } elseif ($category === 'packaging') {
                $qty = '1';
                $comment = '';
                if (preg_match('/Qty:\s*(\d+)\s*\|\s*(.*)$/i', $notes, $matches)) {
                    $qty = $matches[1];
                    $comment = $matches[2];
                }

                $packaging[] = [
                    'item_name' => str_replace('Packaging - ', '', $exp->title),
                    'quantity' => $qty,
                    'amount' => (string)$exp->amount,
                    'comment' => $comment,
                ];
            } else {
                $customCatId = $category;
                if (!isset($custom[$customCatId])) {
                    $custom[$customCatId] = [];
                }
                $custom[$customCatId][] = [
                    'item_name' => str_replace('Custom - ', '', $exp->title),
                    'amount' => (string)$exp->amount,
                    'comment' => $notes,
                ];
            }
        }

        return $this->success([
            'date' => $date,
            'fueling' => [
                'riders' => $fuelingRiders,
                'topUps' => $fuelingTopUps
            ],
            'staff_support' => $staffSupport,
            'maintenance' => $maintenance,
            'office_maintenance' => $officeMaintenance,
            'packaging' => $packaging,
            'custom' => empty($custom) ? new \stdClass() : $custom
        ]);
    }

    public function analytics(Request $request)
    {
        $filter = $request->input('filter', 'month'); // day, week, month, year, custom
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        
        $startDate = now();
        $endDate = now();
        
        if ($dateFrom && $dateTo) {
            try {
                $startDate = \Carbon\Carbon::parse($dateFrom)->startOfDay();
                $endDate = \Carbon\Carbon::parse($dateTo)->endOfDay();
                $filter = 'custom';
            } catch (\Exception $e) {
                // fallback to default
                $startDate = now()->startOfMonth();
                $endDate = now()->endOfMonth();
                $filter = 'month';
            }
        } else {
            switch ($filter) {
                case 'day':
                    $startDate = now()->startOfDay();
                    $endDate = now()->endOfDay();
                    break;
                case 'week':
                    $startDate = now()->startOfWeek();
                    $endDate = now()->endOfWeek();
                    break;
                case 'month':
                    $startDate = now()->startOfMonth();
                    $endDate = now()->endOfMonth();
                    break;
                case 'year':
                    $startDate = now()->startOfYear();
                    $endDate = now()->endOfYear();
                    break;
                default:
                    $startDate = now()->startOfMonth();
                    $endDate = now()->endOfMonth();
                    $filter = 'month';
                    break;
            }
        }
        
        // Dynamic dates
        $startStr = $startDate->toDateString();
        $endStr = $endDate->toDateString();
        
        // Total expenses
        $totalExpenses = (float) Expense::whereBetween('expense_date', [$startStr, $endStr])
            ->sum('amount');
            
        // By Category
        $byCategoryRaw = Expense::whereBetween('expense_date', [$startStr, $endStr])
            ->select('category')
            ->selectRaw('SUM(amount) as total')
            ->groupBy('category')
            ->get();
            
        $byCategory = [];
        foreach ($byCategoryRaw as $item) {
            $catLabel = str_replace('_', ' ', $item->category);
            $byCategory[] = [
                'category' => ucwords($catLabel),
                'amount' => (float)$item->total,
                'percentage' => $totalExpenses > 0 ? round(($item->total / $totalExpenses) * 100, 1) : 0
            ];
        }
            
        // Trend Data
        $trend = [];
        if ($filter === 'day') {
            // Day trend: list actual log timings or 4-hour intervals
            $expenses = Expense::whereBetween('expense_date', [$startStr, $endStr])
                ->orderBy('created_at', 'asc')
                ->get();
                
            foreach ($expenses as $exp) {
                $trend[] = [
                    'label' => $exp->created_at ? $exp->created_at->format('H:i') : '12:00',
                    'amount' => (float)$exp->amount,
                ];
            }
            if (empty($trend)) {
                $trend[] = ['label' => '08:00', 'amount' => 0];
                $trend[] = ['label' => '12:00', 'amount' => 0];
                $trend[] = ['label' => '16:00', 'amount' => 0];
                $trend[] = ['label' => '20:00', 'amount' => 0];
            }
        } elseif ($filter === 'week') {
            // Week trend: Monday through Sunday (7 days)
            $days = [];
            for ($i = 0; $i < 7; $i++) {
                $date = now()->startOfWeek()->addDays($i);
                $days[$date->toDateString()] = [
                    'label' => $date->format('D'),
                    'amount' => 0
                ];
            }
            $expenses = Expense::whereBetween('expense_date', [$startStr, $endStr])
                ->selectRaw('expense_date, SUM(amount) as total')
                ->groupBy('expense_date')
                ->get();
            foreach ($expenses as $exp) {
                $dString = $exp->expense_date->toDateString();
                if (isset($days[$dString])) {
                    $days[$dString]['amount'] = (float)$exp->total;
                }
            }
            $trend = array_values($days);
        } elseif ($filter === 'month') {
            // Month trend: dynamic weekly slots
            $weeks = [];
            $startOfMonth = now()->startOfMonth();
            $endOfMonth = now()->endOfMonth();
            
            $current = $startOfMonth->clone();
            $weekNum = 1;
            while ($current->lte($endOfMonth)) {
                $wStart = $current->clone()->startOfWeek();
                $wEnd = $current->clone()->endOfWeek();
                
                // Adjust bounds
                if ($wStart->lt($startOfMonth)) $wStart = $startOfMonth->clone();
                if ($wEnd->gt($endOfMonth)) $wEnd = $endOfMonth->clone();
                
                $amt = (float) Expense::whereBetween('expense_date', [$wStart->toDateString(), $wEnd->toDateString()])
                    ->sum('amount');
                    
                $weeks[] = [
                    'label' => 'Week ' . $weekNum,
                    'amount' => $amt
                ];
                
                $current->addWeek();
                $weekNum++;
            }
            $trend = $weeks;
        } elseif ($filter === 'year') {
            // Year trend: 12 months
            $months = [];
            for ($i = 1; $i <= 12; $i++) {
                $date = now()->startOfYear()->month($i);
                $months[$i] = [
                    'label' => $date->format('M'),
                    'amount' => 0
                ];
            }
            $expenses = Expense::whereBetween('expense_date', [$startStr, $endStr])
                ->selectRaw('MONTH(expense_date) as month_num, SUM(amount) as total')
                ->groupBy('month_num')
                ->get();
            foreach ($expenses as $exp) {
                $mNum = (int)$exp->month_num;
                if (isset($months[$mNum])) {
                    $months[$mNum]['amount'] = (float)$exp->total;
                }
            }
            $trend = array_values($months);
        } elseif ($filter === 'custom') {
            $diffDays = $startDate->diffInDays($endDate);
            if ($diffDays <= 31) {
                // Daily breakdowns
                $days = [];
                $current = $startDate->clone();
                while ($current->lte($endDate)) {
                    $dString = $current->toDateString();
                    $days[$dString] = [
                        'label' => $current->format('d M'),
                        'amount' => 0
                    ];
                    $current->addDay();
                }
                
                $expenses = Expense::whereBetween('expense_date', [$startStr, $endStr])
                    ->selectRaw('expense_date, SUM(amount) as total')
                    ->groupBy('expense_date')
                    ->get();
                    
                foreach ($expenses as $exp) {
                    $dString = $exp->expense_date->toDateString();
                    if (isset($days[$dString])) {
                        $days[$dString]['amount'] = (float)$exp->total;
                    }
                }
                $trend = array_values($days);
            } else {
                // Monthly breakdowns
                $months = [];
                $current = $startDate->clone();
                while ($current->lte($endDate)) {
                    $mKey = $current->format('Y-m');
                    $months[$mKey] = [
                        'label' => $current->format('M Y'),
                        'amount' => 0
                    ];
                    $current->addMonth();
                }
                
                $expenses = Expense::whereBetween('expense_date', [$startStr, $endStr])
                    ->selectRaw("DATE_FORMAT(expense_date, '%Y-%m') as m_key, SUM(amount) as total")
                    ->groupBy('m_key')
                    ->get();
                    
                foreach ($expenses as $exp) {
                    $mKey = $exp->m_key;
                    if (isset($months[$mKey])) {
                        $months[$mKey]['amount'] = (float)$exp->total;
                    }
                }
                $trend = array_values($months);
            }
        }
        
        // Top Expenses
        $topExpenses = Expense::whereBetween('expense_date', [$startStr, $endStr])
            ->orderBy('amount', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($exp) {
                $catLabel = str_replace('_', ' ', $exp->category);
                return [
                    'id' => $exp->id,
                    'title' => $exp->title,
                    'category' => ucwords($catLabel),
                    'amount' => (float)$exp->amount,
                    'date' => $exp->expense_date,
                    'notes' => $exp->notes
                ];
            });
            
        // Detailed Items (for Excel/PDF report exports)
        $allExpenses = Expense::whereBetween('expense_date', [$startStr, $endStr])
            ->with(['vehicle', 'dispatcher'])
            ->orderBy('expense_date', 'desc')
            ->get()
            ->map(function ($exp) {
                $catLabel = str_replace('_', ' ', $exp->category);
                return [
                    'id' => $exp->id,
                    'title' => $exp->title,
                    'category' => ucwords($catLabel),
                    'amount' => (float)$exp->amount,
                    'date' => $exp->expense_date,
                    'dispatcher' => $exp->dispatcher ? $exp->dispatcher->name : null,
                    'vehicle' => $exp->vehicle ? $exp->vehicle->plate_number : null,
                    'notes' => $exp->notes
                ];
            });

        // Additional stats
        $activeCategoriesCount = count($byCategory);
        $averageCost = count($allExpenses) > 0 ? $totalExpenses / count($allExpenses) : 0;
        $highestSingleExpense = count($topExpenses) > 0 ? $topExpenses[0]['amount'] : 0;
            
        return $this->success([
            'total_expenses' => $totalExpenses,
            'by_category' => $byCategory,
            'trend' => $trend,
            'top_expenses' => $topExpenses,
            'all_expenses' => $allExpenses,
            'stats' => [
                'active_categories' => $activeCategoriesCount,
                'average_cost' => round($averageCost, 2),
                'highest_expense' => $highestSingleExpense,
                'total_count' => count($allExpenses)
            ]
        ]);
    }
}
