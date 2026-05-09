<?php

namespace Database\Seeders;

use App\Models\Inventory;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['warehouse_id' => 1, 'item_name' => 'Bubble Wrap (Large)', 'sku' => 'BW-001', 'category' => 'packaging_material', 'quantity' => 50, 'reorder_level' => 20, 'unit' => 'rolls', 'unit_cost' => 1500],
            ['warehouse_id' => 1, 'item_name' => 'Bubble Wrap (Small)', 'sku' => 'BW-002', 'category' => 'packaging_material', 'quantity' => 8, 'reorder_level' => 15, 'unit' => 'rolls', 'unit_cost' => 800],
            ['warehouse_id' => 1, 'item_name' => 'Carton Box (Small)', 'sku' => 'CB-SM', 'category' => 'packaging_material', 'quantity' => 100, 'reorder_level' => 30, 'unit' => 'pieces', 'unit_cost' => 500],
            ['warehouse_id' => 1, 'item_name' => 'Carton Box (Medium)', 'sku' => 'CB-MD', 'category' => 'packaging_material', 'quantity' => 80, 'reorder_level' => 25, 'unit' => 'pieces', 'unit_cost' => 750],
            ['warehouse_id' => 1, 'item_name' => 'Carton Box (Large)', 'sku' => 'CB-LG', 'category' => 'packaging_material', 'quantity' => 5, 'reorder_level' => 20, 'unit' => 'pieces', 'unit_cost' => 1200],
            ['warehouse_id' => 2, 'item_name' => 'Packing Tape', 'sku' => 'PT-001', 'category' => 'packaging_material', 'quantity' => 40, 'reorder_level' => 15, 'unit' => 'rolls', 'unit_cost' => 300],
            ['warehouse_id' => 2, 'item_name' => 'Stretch Film', 'sku' => 'SF-001', 'category' => 'packaging_material', 'quantity' => 20, 'reorder_level' => 10, 'unit' => 'rolls', 'unit_cost' => 2500],
            ['warehouse_id' => 2, 'item_name' => 'Pallet Jack', 'sku' => 'EQ-PJ', 'category' => 'equipment', 'quantity' => 3, 'reorder_level' => 1, 'unit' => 'pieces', 'unit_cost' => 50000],
        ];

        foreach ($items as $item) {
            Inventory::create($item);
        }
    }
}
