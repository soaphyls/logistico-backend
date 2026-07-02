<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Traits\PartnerModuleTrait;
use App\Models\PartnerProduct;
use App\Models\Notification;
use Illuminate\Http\Request;

class PartnerProductController extends Controller
{
    use PartnerModuleTrait;

    private array $adminRoles = ['super_admin', 'operations_manager', 'operations'];

    public function index(Request $request)
    {
        $this->checkModuleEnabled();

        $user = auth()->user();
        $isAdmin = $user && $user->hasAnyRole($this->adminRoles);

        $query = PartnerProduct::with(['partnerCustomer.customer', 'partnerCustomer.partner', 'approver']);

        $partnerCustomerId = $request->partner_customer_id ?? $request->customer_id;
        if ($partnerCustomerId) {
            $query->where('partner_customer_id', $partnerCustomerId);
        }

        if ($request->is_low_stock) {
            $query->whereColumn('quantity', '<=', 'reorder_level');
        }

        if ($request->is_approved !== null) {
            $query->where('is_approved', $request->is_approved === 'true');
        } elseif (!$isAdmin) {
            $query->where('is_approved', true);
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('sku', 'like', "%{$request->search}%");
            });
        }

        $products = $query->orderBy('id', 'desc')->paginate($request->per_page ?? 20);

        return $this->success($products);
    }

    public function pending(Request $request)
    {
        $this->checkModuleEnabled();

        $query = PartnerProduct::with(['partnerCustomer.customer', 'partnerCustomer.partner', 'approver'])
            ->where('is_approved', false);

        $partnerCustomerId = $request->partner_customer_id ?? $request->customer_id;
        if ($partnerCustomerId) {
            $query->where('partner_customer_id', $partnerCustomerId);
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('sku', 'like', "%{$request->search}%");
            });
        }

        $products = $query->paginate($request->per_page ?? 20);

        return $this->success($products);
    }

    public function approve(Request $request, $id)
    {
        $this->checkModuleEnabled();

        $product = PartnerProduct::findOrFail($id);

        $validated = $request->validate([
            'quantity' => 'sometimes|integer|min:0',
            'warehouse_location' => 'nullable|string',
        ]);

        $product->update([
            'is_approved' => true,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'rejection_reason' => null,
            'quantity' => $validated['quantity'] ?? $product->quantity,
            'warehouse_location' => $validated['warehouse_location'] ?? null,
        ]);

        // Notify partner via in-app notification
        Notification::create([
            'user_id' => $product->partnerCustomer->partner_id,
            'title' => 'Product Approved',
            'message' => "Your product '{$product->name}' has been approved.",
            'type' => 'product',
            'related_to_type' => PartnerProduct::class,
            'related_to_id' => $product->id,
        ]);

        // Notify product owner (partner) via bot when approval is completed.
        dispatch(function () use ($product) {
            try {
                $freshProduct = $product->fresh(['partnerCustomer.partner']);
                $partner = $freshProduct?->partnerCustomer?->partner;

                if ($partner) {
                    $message = "✅ <b>Product Approved</b>\n\n";
                    $message .= "📦 Product: <b>{$freshProduct->name}</b>\n";
                    $message .= "🔢 SKU: <code>" . ($freshProduct->sku ?? 'N/A') . "</code>\n";
                    $message .= "📊 Quantity: <b>{$freshProduct->quantity}</b>\n";
                    $message .= "📍 Warehouse Location: <b>" . ($freshProduct->warehouse_location ?? 'N/A') . "</b>\n\n";
                    $message .= "You can now create orders for this product via the bot.";

                    $notified = app(\App\Services\Bot\BotEngine::class)->notifyUser((int) $partner->id, $message);
                    if (!$notified) {
                        \Illuminate\Support\Facades\Log::warning('Product approved but partner bot notification not delivered', [
                            'product_id' => $freshProduct->id,
                            'partner_id' => $partner->id,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send product approval bot notification: ' . $e->getMessage(), [
                    'product_id' => $product->id,
                ]);
            }
        })->afterResponse();

        return $this->success($product->fresh(['partnerCustomer.customer', 'approver']), 'Product approved successfully');
    }

    public function reject(Request $request, $id)
    {
        $this->checkModuleEnabled();

        $product = PartnerProduct::findOrFail($id);

        $validated = $request->validate([
            'rejection_reason' => 'required|string',
        ]);

        $product->update([
            'is_approved' => false,
            'rejection_reason' => $validated['rejection_reason'],
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        // Notify partner via in-app notification
        Notification::create([
            'user_id' => $product->partnerCustomer->partner_id,
            'title' => 'Product Rejected',
            'message' => "Your product '{$product->name}' was rejected. Reason: {$validated['rejection_reason']}",
            'type' => 'product',
            'related_to_type' => PartnerProduct::class,
            'related_to_id' => $product->id,
        ]);

        return $this->success($product->fresh(['partnerCustomer.customer', 'approver']), 'Product rejected');
    }

    public function store(Request $request)
    {
        $this->checkModuleEnabled();
        $validated = $request->validate([
            'partner_customer_id' => 'required|exists:partner_customers,id',
            'sku' => 'nullable|string',
            'name' => 'required|string',
            'description' => 'nullable|string',
            'quantity' => 'nullable|integer|min:0',
            'defective_quantity' => 'nullable|integer|min:0',
            'defective_comment' => 'nullable|string',
            'reorder_level' => 'nullable|integer|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'storage_location' => 'nullable|string',
        ]);

        if (!isset($validated['quantity'])) {
            $validated['quantity'] = 0;
        }
        if (!isset($validated['defective_quantity'])) {
            $validated['defective_quantity'] = 0;
        }
        if (!isset($validated['reorder_level'])) {
            $validated['reorder_level'] = 10;
        }

        $product = PartnerProduct::create($validated);

        // Notify admins about new product submission
        $this->notifyAdmins(
            'New Product Submitted',
            "A new product '{$product->name}' has been submitted for approval.",
            'product',
            $product
        );

        return $this->success($product->load(['partnerCustomer.partner']), 'Product added successfully', 201);
    }

    public function update(Request $request, $id)
    {
        $this->checkModuleEnabled();
        $product = PartnerProduct::findOrFail($id);

        $validated = $request->validate([
            'sku' => 'nullable|string',
            'name' => 'sometimes|string',
            'description' => 'nullable|string',
            'quantity' => 'sometimes|integer|min:0',
            'defective_quantity' => 'sometimes|integer|min:0',
            'defective_comment' => 'nullable|string',
            'reorder_level' => 'nullable|integer|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'storage_location' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $product->update($validated);

        return $this->success($product->load(['partnerCustomer.partner']), 'Product updated successfully');
    }

    public function destroy($id)
    {
        $this->checkModuleEnabled();
        $product = PartnerProduct::findOrFail($id);
        $product->delete();

        return $this->success(null, 'Product deleted successfully');
    }
}
