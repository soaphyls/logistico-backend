<?php

namespace App\Services;

use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransfer;
use App\Models\InboundOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class InventoryService
{
    public function allocate(int $stockId, int $quantity, string $referenceType = null, int $referenceId = null): bool
    {
        $stock = InventoryStock::findOrFail($stockId);
        
        if ($quantity > $stock->quantity_available) {
            return false;
        }

        DB::transaction(function () use ($stock, $quantity, $referenceType, $referenceId) {
            $stock->quantity_allocated += $quantity;
            $stock->save();

            InventoryTransaction::create([
                'inventory_stock_id' => $stock->id,
                'type' => InventoryTransaction::TYPE_ALLOCATION,
                'quantity_change' => -$quantity,
                'quantity_before' => $stock->quantity_on_hand,
                'quantity_after' => $stock->quantity_on_hand,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'user_id' => Auth::id(),
                'notes' => 'Allocated for fulfillment',
            ]);
        });

        return true;
    }

    public function deallocate(int $stockId, int $quantity, string $referenceType = null, int $referenceId = null): bool
    {
        $stock = InventoryStock::findOrFail($stockId);
        
        $actualDeallocate = min($quantity, $stock->quantity_allocated);

        DB::transaction(function () use ($stock, $actualDeallocate, $referenceType, $referenceId) {
            $stock->quantity_allocated = max(0, $stock->quantity_allocated - $actualDeallocate);
            $stock->save();

            InventoryTransaction::create([
                'inventory_stock_id' => $stock->id,
                'type' => InventoryTransaction::TYPE_DEALLOCATION,
                'quantity_change' => $actualDeallocate,
                'quantity_before' => $stock->quantity_on_hand,
                'quantity_after' => $stock->quantity_on_hand,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'user_id' => Auth::id(),
                'notes' => 'Deallocated',
            ]);
        });

        return true;
    }

    public function receive(int $stockId, int $quantity, string $referenceType = null, int $referenceId = null, string $notes = null): InventoryStock
    {
        $stock = InventoryStock::findOrFail($stockId);
        
        DB::transaction(function () use ($stock, $quantity, $referenceType, $referenceId, $notes) {
            $quantityBefore = $stock->quantity_on_hand;
            $stock->quantity_on_hand += $quantity;
            $stock->save();

            InventoryTransaction::create([
                'inventory_stock_id' => $stock->id,
                'type' => InventoryTransaction::TYPE_RECEIVE,
                'quantity_change' => $quantity,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $stock->quantity_on_hand,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'user_id' => Auth::id(),
                'notes' => $notes ?? 'Stock received',
            ]);
        });

        return $stock;
    }

    public function fulfill(int $stockId, int $quantity, string $referenceType = null, int $referenceId = null): bool
    {
        $stock = InventoryStock::findOrFail($stockId);
        
        if ($quantity > $stock->quantity_on_hand) {
            return false;
        }

        DB::transaction(function () use ($stock, $quantity, $referenceType, $referenceId) {
            $quantityBefore = $stock->quantity_on_hand;
            $stock->quantity_on_hand -= $quantity;
            $stock->quantity_allocated -= $quantity;
            $stock->save();

            InventoryTransaction::create([
                'inventory_stock_id' => $stock->id,
                'type' => InventoryTransaction::TYPE_FULFILL,
                'quantity_change' => -$quantity,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $stock->quantity_on_hand,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'user_id' => Auth::id(),
                'notes' => 'Order fulfilled',
            ]);
        });

        return true;
    }

    public function adjust(int $stockId, int $newQuantity, string $reason): InventoryStock
    {
        $stock = InventoryStock::findOrFail($stockId);
        
        DB::transaction(function () use ($stock, $newQuantity, $reason) {
            $quantityBefore = $stock->quantity_on_hand;
            $quantityChange = $newQuantity - $quantityBefore;
            
            $stock->quantity_on_hand = $newQuantity;
            $stock->save();

            InventoryTransaction::create([
                'inventory_stock_id' => $stock->id,
                'type' => InventoryTransaction::TYPE_ADJUSTMENT,
                'quantity_change' => $quantityChange,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $stock->quantity_on_hand,
                'user_id' => Auth::id(),
                'notes' => 'Adjustment: ' . $reason,
            ]);
        });

        return $stock;
    }

    public function transfer(int $transferId): bool
    {
        $transfer = InventoryTransfer::findOrFail($transferId);
        
        $sourceStock = InventoryStock::where('warehouse_id', $transfer->source_warehouse_id)
            ->where('product_id', $transfer->product_id)
            ->first();
            
        if (!$sourceStock || $transfer->quantity > $sourceStock->quantity_available) {
            return false;
        }

        DB::transaction(function () use ($transfer, $sourceStock) {
            $sourceStock->quantity_on_hand -= $transfer->quantity;
            $sourceStock->quantity_allocated -= $transfer->quantity;
            $sourceStock->save();

            InventoryTransaction::create([
                'inventory_stock_id' => $sourceStock->id,
                'type' => InventoryTransaction::TYPE_TRANSFER,
                'quantity_change' => -$transfer->quantity,
                'quantity_before' => $sourceStock->quantity_on_hand + $transfer->quantity,
                'quantity_after' => $sourceStock->quantity_on_hand,
                'reference_type' => InventoryTransfer::class,
                'reference_id' => $transfer->id,
                'user_id' => Auth::id(),
                'notes' => 'Transfer out to ' . $transfer->destinationWarehouse->name,
            ]);

            $destStock = InventoryStock::firstOrCreate(
                [
                    'warehouse_id' => $transfer->destination_warehouse_id,
                    'product_id' => $transfer->product_id,
                ],
                [
                    'product_name' => $transfer->product_name,
                    'sku' => $transfer->sku,
                    'quantity_on_hand' => 0,
                    'quantity_allocated' => 0,
                ]
            );

            $destStock->quantity_on_hand += $transfer->quantity;
            $destStock->save();

            InventoryTransaction::create([
                'inventory_stock_id' => $destStock->id,
                'type' => InventoryTransaction::TYPE_TRANSFER,
                'quantity_change' => $transfer->quantity,
                'quantity_before' => $destStock->quantity_on_hand - $transfer->quantity,
                'quantity_after' => $destStock->quantity_on_hand,
                'reference_type' => InventoryTransfer::class,
                'reference_id' => $transfer->id,
                'user_id' => Auth::id(),
                'notes' => 'Transfer in from ' . $transfer->sourceWarehouse->name,
            ]);
        });

        return true;
    }

    public function receiveInbound(InboundOrder $inboundOrder, int $receivedQuantity): InboundOrder
    {
        $stock = InventoryStock::firstOrCreate(
            [
                'warehouse_id' => $inboundOrder->warehouse_id,
                'product_id' => $inboundOrder->partner_product_id,
            ],
            [
                'product_name' => $inboundOrder->product_name,
                'sku' => $inboundOrder->sku,
                'quantity_on_hand' => 0,
                'quantity_allocated' => 0,
            ]
        );

        DB::transaction(function () use ($inboundOrder, $receivedQuantity, $stock) {
            $quantityBefore = $stock->quantity_on_hand;
            $stock->quantity_on_hand += $receivedQuantity;
            $stock->save();

            $inboundOrder->received_quantity += $receivedQuantity;
            
            if ($inboundOrder->received_quantity >= $inboundOrder->expected_quantity) {
                $inboundOrder->status = InboundOrder::STATUS_RECEIVED;
            } elseif ($inboundOrder->received_quantity > 0) {
                $inboundOrder->status = InboundOrder::STATUS_PARTIALLY_RECEIVED;
            }
            
            $inboundOrder->received_at = now();
            $inboundOrder->received_by = Auth::id();
            $inboundOrder->save();

            InventoryTransaction::create([
                'inventory_stock_id' => $stock->id,
                'type' => InventoryTransaction::TYPE_RECEIVE,
                'quantity_change' => $receivedQuantity,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $stock->quantity_on_hand,
                'reference_type' => InboundOrder::class,
                'reference_id' => $inboundOrder->id,
                'user_id' => Auth::id(),
                'notes' => 'Inbound receiving: ' . $inboundOrder->reference_number,
            ]);
        });

        return $inboundOrder;
    }

    public function getStockHistory(int $stockId): \Illuminate\Database\Eloquent\Collection
    {
        return InventoryTransaction::where('inventory_stock_id', $stockId)
            ->orderBy('id', 'desc')
            ->get();
    }
}