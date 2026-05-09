<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CycleCount;
use App\Models\CycleCountLine;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CycleCountController extends Controller
{
    public function index(Request $request)
    {
        $query = CycleCount::with(['warehouse', 'assignedToUser']);

        if ($request->has('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $counts = $query->orderBy('id', 'desc')->paginate(20);

        return $this->success($counts);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'notes' => 'nullable',
        ]);

        $validated['reference_number'] = CycleCount::generateReferenceNumber();
        $validated['status'] = CycleCount::STATUS_PENDING;

        $cycleCount = CycleCount::create($validated);

        return $this->success($cycleCount, 'Cycle count created');
    }

    public function show(CycleCount $cycleCount)
    {
        $cycleCount->load(['warehouse', 'assignedToUser', 'lines.inventoryStock']);

        return $this->success($cycleCount);
    }

    public function assign(Request $request, CycleCount $cycleCount)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $cycleCount->update([
            'assigned_to' => $validated['user_id'],
            'assigned_at' => now(),
        ]);

        return $this->success($cycleCount, 'Cycle count assigned');
    }

    public function start(CycleCount $cycleCount)
    {
        if ($cycleCount->status !== CycleCount::STATUS_PENDING) {
            return $this->error('Cycle count is not pending', 400);
        }

        $stocks = InventoryStock::where('warehouse_id', $cycleCount->warehouse_id)->get();

        foreach ($stocks as $stock) {
            CycleCountLine::create([
                'cycle_count_id' => $cycleCount->id,
                'inventory_stock_id' => $stock->id,
                'system_quantity' => $stock->quantity_on_hand,
            ]);
        }

        $cycleCount->update([
            'status' => CycleCount::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        return $this->success($cycleCount, 'Cycle count started');
    }

    public function submitCount(Request $request, CycleCount $cycleCount)
    {
        $validated = $request->validate([
            'lines' => 'required|array',
            'lines.*.inventory_stock_id' => 'required|exists:inventory_stocks,id',
            'lines.*.counted_quantity' => 'required|integer|min:0',
            'lines.*.variance_reason' => 'nullable|string',
        ]);

        foreach ($validated['lines'] as $line) {
            $countedQty = $line['counted_quantity'];
            
            $countLine = CycleCountLine::where('cycle_count_id', $cycleCount->id)
                ->where('inventory_stock_id', $line['inventory_stock_id'])
                ->first();

            if ($countedQty === '') {
                continue;
            }

            if ($countLine) {
                $variance = $countedQty - $countLine->system_quantity;
                $countLine->update([
                    'counted_quantity' => $countedQty,
                    'variance' => $variance,
                    'variance_reason' => $line['variance_reason'] ?? null,
                ]);
            }
        }

        return $this->success($cycleCount, 'Count submitted');
    }

    public function complete(CycleCount $cycleCount)
    {
        if ($cycleCount->status !== CycleCount::STATUS_IN_PROGRESS) {
            return $this->error('Cycle count is not in progress', 400);
        }

        $cycleCount->update([
            'status' => CycleCount::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        return $this->success($cycleCount, 'Cycle count completed');
    }

    public function adjust(Request $request, CycleCount $cycleCount)
    {
        $validated = $request->validate([
            'lines' => 'required|array',
            'lines.*.inventory_stock_id' => 'required|exists:inventory_stocks,id',
            'lines.*.counted_quantity' => 'required|integer|min:0',
        ]);

        foreach ($validated['lines'] as $line) {
            $countLine = CycleCountLine::where('cycle_count_id', $cycleCount->id)
                ->where('inventory_stock_id', $line['inventory_stock_id'])
                ->first();

            if (!$countLine || $countLine->is_adjusted) {
                continue;
            }

            $stock = InventoryStock::find($line['inventory_stock_id']);
            $variance = $line['counted_quantity'] - $countLine->system_quantity;

            $stock->quantity_on_hand = $line['counted_quantity'];
            $stock->save();

            InventoryTransaction::create([
                'inventory_stock_id' => $stock->id,
                'type' => InventoryTransaction::TYPE_ADJUSTMENT,
                'quantity_change' => $variance,
                'quantity_before' => $countLine->system_quantity,
                'quantity_after' => $line['counted_quantity'],
                'reference_type' => CycleCount::class,
                'reference_id' => $cycleCount->id,
                'user_id' => Auth::id(),
                'notes' => 'Cycle count adjustment',
            ]);

            $countLine->update(['is_adjusted' => true]);
        }

        $cycleCount->update(['status' => CycleCount::STATUS_ADJUSTED]);

        return $this->success($cycleCount, 'Adjustments applied');
    }
}