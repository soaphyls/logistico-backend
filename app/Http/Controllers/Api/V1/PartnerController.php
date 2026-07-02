<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PartnerModule;
use App\Models\PartnerCustomer;
use App\Models\PartnerProduct;
use App\Models\FulfillmentRequest;
use App\Models\FulfillmentActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Shipment;
use App\Models\Dispatcher;
use App\Models\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PartnerController extends Controller
{
    private function checkModuleEnabled(): void
    {
        if (!PartnerModule::isEnabled()) {
            throw new \Exception('Partner module is not enabled');
        }
    }

    public function moduleStatus()
    {
        $module = PartnerModule::first();
        return $this->success([
            'is_enabled' => $module ? $module->is_enabled : false,
        ]);
    }

    public function toggleModule(Request $request)
    {
        $user = auth()->user();
        if (!$user->isSuperAdmin()) {
            return $this->error('Only super admins can toggle the module', 403);
        }

        $module = PartnerModule::first();
        if (!$module) {
            $module = PartnerModule::create(['is_enabled' => true]);
        } else {
            $module->update(['is_enabled' => !$module->is_enabled]);
        }

        return $this->success([
            'is_enabled' => $module->is_enabled,
        ], $module->is_enabled ? 'Partner module enabled' : 'Partner module disabled');
    }

    public function dashboard()
    {
        try {
            $this->checkModuleEnabled();
        } catch (\Exception $e) {
            return $this->success([
                'total_customers' => 0,
                'total_products' => 0,
                'total_requests' => 0,
                'pending_requests' => 0,
                'processing_requests' => 0,
                'delivered_requests' => 0,
                'cancelled_requests' => 0,
                'total_revenue' => 0,
                'recent_requests' => [],
            ]);
        }

        $totalCustomers = PartnerCustomer::count();
        $totalProducts = PartnerProduct::where('is_approved', true)->count();
        $requestCounts = FulfillmentRequest::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
            
        $totalRequests = array_sum($requestCounts);
        $pendingRequests = $requestCounts['pending'] ?? 0;
        $processingRequests = $requestCounts['processing'] ?? 0;
        $outForDeliveryRequests = $requestCounts['out_for_delivery'] ?? 0;
        $deliveredRequests = $requestCounts['delivered'] ?? 0;
        $failedRequests = ($requestCounts['failed'] ?? 0) + ($requestCounts['cancelled'] ?? 0);

        $recentRequests = FulfillmentRequest::with(['partnerCustomer.customer', 'partnerCustomer.partner', 'partnerProduct'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $totalRevenue = Invoice::where('status', 'paid')->sum('total_amount');

        return $this->success([
            'total_customers' => $totalCustomers,
            'total_products' => $totalProducts,
            'total_requests' => $totalRequests,
            'pending_requests' => $pendingRequests,
            'processing_requests' => $processingRequests,
            'out_for_delivery_requests' => $outForDeliveryRequests,
            'delivered_requests' => $deliveredRequests,
            'failed_requests' => $failedRequests,
            'total_revenue' => $totalRevenue,
            'recent_requests' => $recentRequests,
        ]);
    }

    public function customers(Request $request)
    {
        $this->checkModuleEnabled();

        $user = auth()->user();

        $query = PartnerCustomer::with(['customer', 'partner', 'warehouse', 'staff', 'products', 'fulfillmentRequests']);

        // Staff (except admins and operations) sees only their assigned customers
        if ($user->role && !in_array($user->role->name, ['super_admin', 'operations_manager', 'operations'])) {
            $query->where('staff_id', $user->id);
        }

        if ($request->staff_id) {
            $query->where('staff_id', $request->staff_id);
        }

        if ($request->warehouse_id) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->search) {
            $query->whereHas('customer', function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%")
                    ->orWhere('customer_code', 'like', "%{$request->search}%");
            });
        }

        $customers = $query->orderBy('id', 'desc')->paginate($request->per_page ?? 20);

        return $this->success($customers);
    }

    public function storeCustomer(Request $request)
    {
        $this->checkModuleEnabled();

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'partner_id' => 'nullable|exists:users,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'storage_type' => 'nullable|in:free,paid',
            'storage_rate' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'staff_id' => 'nullable|exists:users,id',
            'customer_name' => 'required_without:customer_id|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'customer_email' => 'nullable|email|max:255',
            'customer_address' => 'nullable|string|max:500',
            'customer_city' => 'nullable|string|max:100',
            'customer_state' => 'nullable|string|max:100',
            'customer_notes' => 'nullable|string',
        ]);

        $validated['created_by'] = auth()->id();

        if (empty($validated['partner_id'])) {
            $validated['partner_id'] = auth()->id();
        }

        if (!isset($validated['storage_rate'])) {
            $validated['storage_rate'] = 0;
        }

        if (!empty($validated['customer_id'])) {
            $exists = PartnerCustomer::where('customer_id', $validated['customer_id'])->exists();
            if ($exists) {
                return $this->error('Customer already has a partner profile', 400);
            }
        }

        $partnerCustomer = PartnerCustomer::create($validated);

        return $this->success($partnerCustomer->load(['customer', 'partner', 'warehouse', 'staff']), 'Partner customer created successfully', 201);
    }

    public function showCustomer($id)
    {
        $this->checkModuleEnabled();

        $partnerCustomer = PartnerCustomer::with(['customer', 'partner', 'warehouse', 'staff', 'products', 'fulfillmentRequests'])->findOrFail($id);
        return $this->success($partnerCustomer);
    }

    public function updateCustomer(Request $request, $id)
    {
        $this->checkModuleEnabled();
        $partnerCustomer = PartnerCustomer::findOrFail($id);

        $validated = $request->validate([
            'partner_id' => 'sometimes|exists:users,id',
            'warehouse_id' => 'sometimes|exists:warehouses,id',
            'storage_type' => 'sometimes|in:free,paid',
            'notes' => 'nullable|string',
            'staff_id' => 'nullable|exists:users,id',
        ]);

        $partnerCustomer->update($validated);

        return $this->success($partnerCustomer->load(['customer', 'partner', 'warehouse', 'staff']), 'Customer updated successfully');
    }

    public function deleteCustomer($id)
    {
        $this->checkModuleEnabled();

        $partnerCustomer = PartnerCustomer::findOrFail($id);

        // Delete related data first
        FulfillmentRequest::where('partner_customer_id', $id)->delete();
        PartnerProduct::where('partner_customer_id', $id)->delete();
        $partnerCustomer->delete();

        return $this->success(null, 'Customer deleted successfully');
    }

    public function assignStaff(Request $request, $id)
    {
        $this->checkModuleEnabled();

        $partnerCustomer = PartnerCustomer::findOrFail($id);

        $validated = $request->validate([
            'staff_id' => 'required|exists:users,id',
        ]);

        $partnerCustomer->update(['staff_id' => $validated['staff_id']]);

        return $this->success($partnerCustomer->load(['customer', 'warehouse', 'staff']), 'Staff assigned successfully');
    }

    public function products(Request $request)
    {
        $this->checkModuleEnabled();

        $user = auth()->user();
        $isAdmin = $user && $user->role && in_array($user->role->name, ['super_admin', 'operations_manager', 'operations']);

        $query = PartnerProduct::with(['partnerCustomer.customer', 'partnerCustomer.partner', 'approver']);

        $filterCustomerId = $request->partner_customer_id ?? $request->customer_id;
        if ($filterCustomerId) {
            $query->where('partner_customer_id', $filterCustomerId);
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

    public function pendingProducts(Request $request)
    {
        $this->checkModuleEnabled();

        $query = PartnerProduct::with(['partnerCustomer.customer', 'partnerCustomer.partner', 'approver'])
            ->where('is_approved', false);

        if ($request->partner_customer_id) {
            $query->where('partner_customer_id', $request->partner_customer_id);
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

    public function approveProduct(Request $request, $id)
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

    public function rejectProduct(Request $request, $id)
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

    public function storeProduct(Request $request)
    {
        $this->checkModuleEnabled();
        $validated = $request->validate([
            'partner_customer_id' => 'required|exists:partner_customers,id',
            'sku' => 'nullable|string',
            'name' => 'required|string',
            'description' => 'nullable|string',
            'quantity' => 'nullable|integer|min:0',
            'reorder_level' => 'nullable|integer|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'storage_location' => 'nullable|string',
        ]);

        if (!isset($validated['quantity'])) {
            $validated['quantity'] = 0;
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

    public function updateProduct(Request $request, $id)
    {
        $this->checkModuleEnabled();
        $product = PartnerProduct::findOrFail($id);

        $validated = $request->validate([
            'sku' => 'nullable|string',
            'name' => 'sometimes|string',
            'description' => 'nullable|string',
            'quantity' => 'sometimes|integer|min:0',
            'reorder_level' => 'nullable|integer|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'storage_location' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $product->update($validated);

        return $this->success($product->load(['partnerCustomer.partner']), 'Product updated successfully');
    }

    public function deleteProduct($id)
    {
        $this->checkModuleEnabled();
        $product = PartnerProduct::findOrFail($id);
        $product->delete();

        return $this->success(null, 'Product deleted successfully');
    }

    public function requests(Request $request)
    {
        try {
            $this->checkModuleEnabled();
        } catch (\Exception $e) {
            return $this->success(['data' => [], 'meta' => []]);
        }

        $user = auth()->user();

        $query = FulfillmentRequest::with([
            'partnerCustomer.customer',
            'partnerCustomer.partner',
            'partnerCustomer.warehouse',
            'partnerProduct',
            'staff',
            'dispatcher.user',
            'invoice'
        ]);

        // Role-based filtering
        if ($user && $user->role && !in_array($user->role->name, ['super_admin', 'operations_manager', 'operations'])) {
            if ($user->role->slug === 'dispatcher') {
                $dispatcher = Dispatcher::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'license_number' => 'DL-' . strtoupper(uniqid()),
                        'license_expiry' => now()->addYear(),
                        'is_available' => true,
                    ]
                );
                $query->where('dispatcher_id', $dispatcher->id);
            } else {
                $query->where('staff_id', $user->id);
            }
        }

        if ($request->partner_customer_id) {
            $query->where('partner_customer_id', $request->partner_customer_id);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->staff_id) {
            $query->where('staff_id', $request->staff_id);
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('delivery_notes', 'like', "%{$request->search}%")
                    ->orWhere('delivery_phone', 'like', "%{$request->search}%")
                    ->orWhere('delivery_address', 'like', "%{$request->search}%")
                    ->orWhereHas('partnerCustomer', function ($pc) use ($request) {
                        $pc->where('customer_name', 'like', "%{$request->search}%")
                            ->orWhereHas('customer', function ($c) use ($request) {
                                $c->where('name', 'like', "%{$request->search}%")
                                    ->orWhere('company_name', 'like', "%{$request->search}%");
                            })
                            ->orWhereHas('partner', function ($p) use ($request) {
                                $p->where('company', 'like', "%{$request->search}%")
                                    ->orWhere('name', 'like', "%{$request->search}%");
                            });
                    })
                    ->orWhereHas('partnerProduct', function ($pp) use ($request) {
                        $pp->where('name', 'like', "%{$request->search}%");
                    });
            });
        }

        $requests = $query->orderBy('id', 'desc')->paginate($request->per_page ?? 20);

        return $this->success($requests);
    }

    public function createRequest(Request $request)
    {
        $this->checkModuleEnabled();
        $validated = $request->validate([
            'partner_customer_id' => 'required|exists:partner_customers,id',
            'partner_product_id' => 'nullable|exists:partner_products,id',
            'quantity' => 'nullable|integer|min:1',
            'delivery_address' => 'required|string',
            'delivery_city' => 'nullable|string',
            'delivery_state' => 'nullable|string',
            'delivery_phone' => 'required|string',
            'delivery_notes' => 'nullable|string',
            'requested_by' => 'nullable|string',
            'notes' => 'nullable|string',
            'request_type' => 'nullable|string|in:pickup,delivery',
            'contact_person' => 'nullable|string',
        ]);

        $partnerCustomer = PartnerCustomer::with('staff')->findOrFail($validated['partner_customer_id']);

        // Assign to staff if not assigned
        if (!$partnerCustomer->staff_id) {
            return $this->error('No staff assigned to this customer. Please assign a staff first.', 400);
        }

        $validated['staff_id'] = $partnerCustomer->staff_id;
        $validated['requested_at'] = now();

        // Handle product if provided
        if (!empty($validated['partner_product_id'])) {
            $product = PartnerProduct::findOrFail($validated['partner_product_id']);
            if ($product->quantity < ($validated['quantity'] ?? 1)) {
                return $this->error('Insufficient stock. Available: ' . $product->quantity, 400);
            }
            
            // Set COD fields
            $validated['cod_amount'] = ($product->unit_cost ?? 0) * ($validated['quantity'] ?? 1);
            $validated['remittance_amount'] = $validated['cod_amount']; // delivery_cost is 0 initially
            
            $product->decrement('quantity', $validated['quantity'] ?? 1);
        }

        if (empty($validated['delivery_notes']) && !empty($validated['contact_person'])) {
            $validated['delivery_notes'] = $validated['contact_person'];
        }

        $fulfillmentRequest = FulfillmentRequest::create($validated);

        // Notify assigned staff
        Notification::create([
            'user_id' => $fulfillmentRequest->staff_id,
            'title' => 'New Fulfillment Request',
            'message' => "New request #{$fulfillmentRequest->id} from " . ($partnerCustomer->customer->name ?? 'Partner Customer'),
            'type' => 'fulfillment',
            'related_to_type' => FulfillmentRequest::class,
            'related_to_id' => $fulfillmentRequest->id,
        ]);

        // Notify admins
        $this->notifyAdmins(
            'New Fulfillment Request',
            "Request #{$fulfillmentRequest->request_number} created for " . ($partnerCustomer->customer->name ?? 'Partner Customer'),
            'fulfillment',
            $fulfillmentRequest
        );

        // Log activity
        FulfillmentActivityLog::create([
            'fulfillment_request_id' => $fulfillmentRequest->id,
            'user_id' => auth()->id(),
            'action' => 'created',
            'notes' => 'Fulfillment request created',
        ]);

        // Notify partner via Bot (Asynchronous)
        dispatch(function () use ($fulfillmentRequest) {
            try {
                $botEngine = app(\App\Services\Bot\BotEngine::class);
                $botEngine->notifyPartnerOrderCreated($fulfillmentRequest->load(['partnerCustomer.partner', 'partnerProduct']));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Failed to send order creation notification to partner: " . $e->getMessage());
            }
        })->afterResponse();

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerCustomer.partner', 'partnerProduct', 'staff']), 'Fulfillment request created successfully', 201);
    }

    public function showRequest($id)
    {
        $this->checkModuleEnabled();
        $request = FulfillmentRequest::with([
            'partnerCustomer.customer',
            'partnerCustomer.partner',
            'partnerProduct',
            'staff',
            'picker',
            'dispatcher.user',
            'shipment',
            'invoice',
            'activities.user'
        ])->findOrFail($id);

        return $this->success($request);
    }

    public function acknowledgeRequest(Request $request, $id)
    {
        $this->checkModuleEnabled();
        
        $validated = $request->validate([
            'delivery_cost' => 'required|numeric|min:0',
        ]);

        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        if (!in_array($fulfillmentRequest->status, ['pending', 'processing', 'rejected', 'awaiting_reschedule'])) {
            return $this->error('Request cannot be acknowledged in current status', 400);
        }

        DB::transaction(function () use ($fulfillmentRequest, $validated) {
            $fulfillmentRequest->update([
                'status' => 'awaiting_partner_action',
                'delivery_cost' => $validated['delivery_cost'],
                'picked_by' => auth()->id(),
            ]);

            FulfillmentActivityLog::create([
                'fulfillment_request_id' => $fulfillmentRequest->id,
                'user_id' => auth()->id(),
                'action' => 'acknowledged',
                'notes' => 'Cost set: ' . $validated['delivery_cost'] . ' - Awaiting partner action',
            ]);
        });

        // Non-critical: send notification after response
        dispatch(function () use ($fulfillmentRequest, $validated) {
            Notification::create([
                'user_id' => $fulfillmentRequest->partnerCustomer->partner_id,
                'title' => 'Delivery Cost Set',
                'message' => "Cost for request #{$fulfillmentRequest->id} set to ₦" . number_format($validated['delivery_cost'], 2) . ". Please accept to proceed.",
                'type' => 'fulfillment',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);
        })->afterResponse();

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerProduct']), 'Request acknowledged. Waiting for partner acceptance.');
    }

    public function acceptRequest($id)
    {
        $this->checkModuleEnabled();
        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        if ($fulfillmentRequest->status !== 'awaiting_partner_action' && $fulfillmentRequest->status !== 'rejected') {
            return $this->error('Request is not awaiting partner action', 400);
        }

        DB::transaction(function () use ($fulfillmentRequest) {
            $fulfillmentRequest->update([
                'status' => 'accepted',
            ]);

            FulfillmentActivityLog::create([
                'fulfillment_request_id' => $fulfillmentRequest->id,
                'user_id' => auth()->id(),
                'action' => 'accepted',
                'notes' => 'Partner accepted the delivery cost',
            ]);
        });

        dispatch(function () use ($fulfillmentRequest) {
            Notification::create([
                'user_id' => $fulfillmentRequest->staff_id,
                'title' => 'Partner Accepted Cost',
                'message' => "Partner accepted delivery cost for request #{$fulfillmentRequest->id}",
                'type' => 'fulfillment',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);
        })->afterResponse();

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerProduct']), 'Request accepted');
    }

    public function rejectRequest(Request $request, $id)
    {
        $this->checkModuleEnabled();
        
        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        if ($fulfillmentRequest->status !== 'awaiting_partner_action') {
            return $this->error('Request is not awaiting partner action', 400);
        }

        DB::transaction(function () use ($fulfillmentRequest, $validated) {
            $fulfillmentRequest->update([
                'status' => 'rejected',
                'partner_response' => $validated['reason'],
            ]);

            FulfillmentActivityLog::create([
                'fulfillment_request_id' => $fulfillmentRequest->id,
                'user_id' => auth()->id(),
                'action' => 'rejected',
                'notes' => 'Partner rejected: ' . $validated['reason'],
            ]);
        });

        dispatch(function () use ($fulfillmentRequest, $validated) {
            Notification::create([
                'user_id' => $fulfillmentRequest->staff_id,
                'title' => 'Partner Rejected Cost',
                'message' => "Partner rejected delivery cost for request #{$fulfillmentRequest->id}. Reason: {$validated['reason']}",
                'type' => 'fulfillment',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);
        })->afterResponse();

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerProduct']), 'Request rejected');
    }

    public function assignDispatcher(Request $request, $id)
    {
        $this->checkModuleEnabled();
        $validated = $request->validate([
            'dispatcher_id' => 'required|exists:dispatchers,id',
        ]);

        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        if (!in_array($fulfillmentRequest->status, ['acknowledged', 'accepted'])) {
            return $this->error('Request must be acknowledged/accepted before assigning dispatcher', 400);
        }

        $dispatcher = \App\Models\Dispatcher::findOrFail($validated['dispatcher_id']);

        DB::transaction(function () use ($fulfillmentRequest, $validated) {
            $fulfillmentRequest->update([
                'status' => 'assigned',
                'dispatcher_id' => $validated['dispatcher_id'],
            ]);

            FulfillmentActivityLog::create([
                'fulfillment_request_id' => $fulfillmentRequest->id,
                'user_id' => auth()->id(),
                'action' => 'dispatcher_assigned',
                'notes' => 'Dispatcher assigned for delivery',
            ]);
        });

        // Non-critical notifications after response
        dispatch(function () use ($fulfillmentRequest, $dispatcher) {
            Notification::create([
                'user_id' => $dispatcher->user_id,
                'title' => 'New Job Assigned',
                'message' => "You have been assigned fulfillment request #{$fulfillmentRequest->id}",
                'type' => 'delivery',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);

            try {
                $botEngine = app(\App\Services\Bot\BotEngine::class);
                $notified = $botEngine->notifyDispatcherFulfillmentAssignment(
                    $fulfillmentRequest->load(['dispatcher.user', 'partnerProduct'])
                );

                if (!$notified) {
                    \Illuminate\Support\Facades\Log::warning('Dispatcher bot notification was not delivered for fulfillment assignment', [
                        'fulfillment_request_id' => $fulfillmentRequest->id,
                        'dispatcher_id' => $fulfillmentRequest->dispatcher_id,
                    ]);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to notify dispatcher for fulfillment assignment: ' . $e->getMessage(), [
                    'fulfillment_request_id' => $fulfillmentRequest->id,
                ]);
            }
        })->afterResponse();

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerProduct', 'dispatcher.user']), 'Dispatcher assigned successfully');
    }

    public function startDelivery($id)
    {
        $this->checkModuleEnabled();
        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        $user = auth()->user();
        if ($user->role?->slug === 'dispatcher') {
            $dispatcher = Dispatcher::where('user_id', $user->id)->first();
            if (!$dispatcher || $fulfillmentRequest->dispatcher_id !== $dispatcher->id) {
                return $this->error('Access denied', 403);
            }
        }

        if (!in_array($fulfillmentRequest->status, ['assigned', 'in_progress'])) {
            return $this->error('Request must be assigned to start delivery', 400);
        }

        DB::transaction(function () use ($fulfillmentRequest) {
            $fulfillmentRequest->update([
                'status' => 'in_transit',
            ]);

            FulfillmentActivityLog::create([
                'fulfillment_request_id' => $fulfillmentRequest->id,
                'user_id' => auth()->id(),
                'action' => 'in_transit',
                'notes' => 'Dispatcher started the delivery',
            ]);
        });

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerProduct']), 'Delivery started');
    }

    public function completeRequest(Request $request, $id)
    {
        $this->checkModuleEnabled();
        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        $user = auth()->user();
        if ($user->role?->slug === 'dispatcher') {
            $dispatcher = Dispatcher::where('user_id', $user->id)->first();
            if (!$dispatcher || $fulfillmentRequest->dispatcher_id !== $dispatcher->id) {
                return $this->error('Access denied', 403);
            }
        }

        $isAlreadyDelivered = $fulfillmentRequest->status === 'delivered';

        if (!$isAlreadyDelivered && !in_array($fulfillmentRequest->status, ['assigned', 'in_progress', 'in_transit', 'out_for_delivery'])) {
            return $this->error('Request must be assigned or in transit to complete', 400);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
            'amount_collected' => 'nullable|numeric|min:0',
            'proof_photo' => 'nullable|string', // Base64 string or URL
        ]);

        $amountCollected = $validated['amount_collected'] ?? $fulfillmentRequest->cod_amount;
        
        DB::transaction(function () use ($fulfillmentRequest, $validated, $amountCollected, $isAlreadyDelivered) {
            $fulfillmentRequest->update([
                'status' => 'delivered',
                'amount_collected' => $amountCollected,
                'completed_at' => now(),
                'notes' => $validated['notes'] ?? $fulfillmentRequest->notes,
                'proof_photo' => $validated['proof_photo'] ?? null,
            ]);

            // Notify partner
            Notification::create([
                'user_id' => $fulfillmentRequest->partnerCustomer->partner_id,
                'title' => 'Order Delivered',
                'message' => "Your order #{$fulfillmentRequest->id} has been delivered successfully.",
                'type' => 'delivery',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);

            // Notify assigned staff
            Notification::create([
                'user_id' => $fulfillmentRequest->staff_id,
                'title' => 'Order Delivered',
                'message' => "Fulfillment request #{$fulfillmentRequest->id} has been delivered.",
                'type' => 'delivery',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);

            if (!$isAlreadyDelivered) {
                // Inventory tracking rules...
                $product = $fulfillmentRequest->partnerProduct;
                $stockWasRestored = $product
                    && $fulfillmentRequest->fail_reason === 'Customer rejected the goods'
                    && $fulfillmentRequest->failed_at === null; // admin cleared failed_at on reschedule

                if ($stockWasRestored) {
                    // Check if inventory was already restored (failed orders have their stock returned)
                    $inventoryRestored = \App\Models\FulfillmentActivityLog::where('fulfillment_request_id', $fulfillmentRequest->id)
                        ->where('notes', 'like', '%[Inventory Restored]%')
                        ->exists();

                    if ($inventoryRestored && $fulfillmentRequest->partnerProduct) {
                        \Log::info("Re-deducting stock for previously failed order {$fulfillmentRequest->id}");
                        $fulfillmentRequest->partnerProduct->decrement('quantity', $fulfillmentRequest->quantity ?? 1);
                    }
                    Log::info("Inventory re-decremented on delivery (after prior restore) for request {$fulfillmentRequest->id}: qty={$fulfillmentRequest->quantity}");
                }

                // Update dispatcher stats
                if ($fulfillmentRequest->dispatcher) {
                    $fulfillmentRequest->dispatcher->increment('total_deliveries');
                    $fulfillmentRequest->dispatcher->increment('successful_deliveries');
                }

                FulfillmentActivityLog::create([
                    'fulfillment_request_id' => $fulfillmentRequest->id,
                    'user_id' => auth()->id(),
                    'action' => 'delivered',
                    'notes' => 'Order delivered successfully' . ($validated['notes'] ? ' | Notes: ' . $validated['notes'] : ''),
                ]);
            } else {
                // Just log the update
                FulfillmentActivityLog::create([
                    'fulfillment_request_id' => $fulfillmentRequest->id,
                    'user_id' => auth()->id(),
                    'action' => 'updated_delivery',
                    'notes' => 'Delivery details updated (notes/photo)' . ($validated['notes'] ? ' | Notes: ' . $validated['notes'] : ''),
                ]);
            }
        });

        if (!$isAlreadyDelivered) {
            // Notify partner via Bot (Asynchronous to prevent hanging)
            dispatch(function () use ($fulfillmentRequest) {
                try {
                    $botEngine = app(\App\Services\Bot\BotEngine::class);
                    $botEngine->notifyPartnerOrderDelivered($fulfillmentRequest);
                } catch (\Exception $e) {
                    \Log::error("Failed to send delivery notification to partner: " . $e->getMessage());
                }
            })->afterResponse();
        }

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerProduct']), 'Delivery completed successfully');
    }

    public function failDelivery(Request $request, $id)
    {
        $this->checkModuleEnabled();

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
            'notes' => 'nullable|string',
            'proof_photo' => 'nullable|string',
        ]);

        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);
        
        // Define failure reasons that trigger rescheduling
        $rescheduleReasons = ['not_reachable'];
        $isReschedule = in_array($validated['reason'], $rescheduleReasons);
        
        // Map slug to label
        $reasonLabels = [
            'customer_rejected' => 'Customer rejected the goods',
            'not_reachable' => 'Customer not reachable',
        ];
        
        $reasonLabel = $reasonLabels[$validated['reason']] ?? $validated['reason'];

        DB::transaction(function () use ($fulfillmentRequest, $validated, $isReschedule, $reasonLabel) {
            // Handle inventory and rescheduling
            if ($isReschedule) {
                // Rescheduled: goods remain out for re-delivery, so do NOT restore inventory.
                // The stock was already decremented on order creation and will be correct
                // when the order is eventually delivered or fails permanently.
                $fulfillmentRequest->update([
                    'status' => 'awaiting_reschedule',
                    'requested_at' => now()->addDay()->startOfDay(),
                    'failed_at' => now(), // Keep track of when it failed (used to detect re-deliver flow)
                    'fail_reason' => $validated['reason'],
                    'failed_by' => auth()->user()->name,
                    'pickup_delivery_id' => null, // Unlink from current delivery
                    'notes' => ($fulfillmentRequest->notes ? $fulfillmentRequest->notes . "\n" : "") . 'Automatic reschedule (not reachable) on ' . now()->format('Y-m-d H:i'),
                ]);
            } else {
                // Final failure: restore product quantity since the goods were not delivered.
                // Guard against null product (orders without a linked product).
                if ($fulfillmentRequest->partnerProduct && $fulfillmentRequest->failed_at === null) {
                    // Only restore if not already restored (idempotent guard using failed_at).
                    $fulfillmentRequest->partnerProduct->increment('quantity', $fulfillmentRequest->quantity ?? 1);
                    
                    \App\Models\FulfillmentActivityLog::create([
                        'fulfillment_request_id' => $fulfillmentRequest->id,
                        'user_id' => auth()->id(),
                        'action' => 'inventory_restored',
                        'notes' => '[Inventory Restored] Stock returned due to final failure: ' . ($validated['reason'] ?? 'customer_rejected'),
                    ]);
                    Log::info("Inventory restored on final failure for request {$fulfillmentRequest->id}: qty={$fulfillmentRequest->quantity}");
                }

                $fulfillmentRequest->update([
                    'status' => 'failed',
                    'failed_at' => now(),
                    'fail_reason' => $validated['reason'],
                    'failed_by' => auth()->user()->name,
                    'notes' => $validated['notes'] ?? $fulfillmentRequest->notes,
                ]);
            }

            // Notify partner
            Notification::create([
                'user_id' => $fulfillmentRequest->partnerCustomer->partner_id,
                'title' => 'Delivery Failed',
                'message' => "Delivery for order #{$fulfillmentRequest->id} failed. Reason: {$reasonLabel}",
                'type' => 'delivery',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);

            // Notify staff
            Notification::create([
                'user_id' => $fulfillmentRequest->staff_id,
                'title' => 'Delivery Failed',
                'message' => "Delivery for request #{$fulfillmentRequest->id} failed.",
                'type' => 'delivery',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);

            // Update dispatcher stats
            if ($fulfillmentRequest->dispatcher && !$isReschedule) {
                $fulfillmentRequest->dispatcher->increment('total_deliveries');
            }

            // Log activity
            FulfillmentActivityLog::create([
                'fulfillment_request_id' => $fulfillmentRequest->id,
                'user_id' => auth()->id(),
                'action' => 'failed',
                'notes' => 'Delivery failed: ' . $reasonLabel . ($validated['notes'] ? ' | Notes: ' . $validated['notes'] : ''),
            ]);

            if ($isReschedule) {
                FulfillmentActivityLog::create([
                    'fulfillment_request_id' => $fulfillmentRequest->id,
                    'user_id' => auth()->id(),
                    'action' => 'rescheduled',
                    'notes' => 'New delivery attempt scheduled for tomorrow',
                ]);
            }
        });

        return $this->success($fulfillmentRequest->fresh(), 'Delivery marked as failed' . ($isReschedule ? ' and rescheduled for tomorrow' : ''));
    }

    public function rescheduleRequest(Request $request, $id)
    {
        $this->checkModuleEnabled();
        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        if ($fulfillmentRequest->status !== 'failed') {
            return $this->error('Only failed orders can be rescheduled', 400);
        }

        // Update the existing order to avoid duplicates
        $fulfillmentRequest->update([
            'status' => 'awaiting_reschedule',
            'requested_at' => now()->addDay()->startOfDay(),
            'failed_at' => null,
            'fail_reason' => null,
            'failed_by' => null,
            'completed_at' => null,
            'pickup_delivery_id' => null,
            'notes' => ($fulfillmentRequest->notes ? $fulfillmentRequest->notes . "\n" : "") . 'Manual reschedule by admin on ' . now()->format('Y-m-d H:i'),
        ]);

        FulfillmentActivityLog::create([
            'fulfillment_request_id' => $fulfillmentRequest->id,
            'user_id' => auth()->id(),
            'action' => 'rescheduled',
            'notes' => 'Admin manually rescheduled the order for tomorrow',
        ]);

        return $this->success($fulfillmentRequest, 'Order rescheduled successfully');
    }

    public function cancelRequest(Request $request, $id)
    {
        $this->checkModuleEnabled();
        $validated = $request->validate([
            'cancel_reason' => 'required|string',
        ]);

        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        if (in_array($fulfillmentRequest->status, ['delivered', 'cancelled'])) {
            return $this->error('Request cannot be cancelled in current status', 400);
        }

        DB::transaction(function () use ($fulfillmentRequest, $validated) {
            // Restore product quantity, but only if:
            // 1. A product is linked to this order.
            // 2. Stock was not already restored by a prior final-failure action.
            //
            // Stock is ONLY pre-restored when an order reaches 'failed' status with reason
            // 'customer_rejected'. An 'awaiting_reschedule' order (not_reachable path) still
            // has its stock out (not restored), so cancelling it must restore stock.
            $product = $fulfillmentRequest->partnerProduct;
            if ($product) {
                // Stock was already restored ONLY if the order is in 'failed' state
                // (final failure where customer_rejected triggered an increment).
                $stockAlreadyRestored = $fulfillmentRequest->status === 'failed';
                if (!$stockAlreadyRestored) {
                    $product->increment('quantity', $fulfillmentRequest->quantity ?? 1);
                    
                    \App\Models\FulfillmentActivityLog::create([
                        'fulfillment_request_id' => $fulfillmentRequest->id,
                        'user_id' => auth()->id(),
                        'action' => 'inventory_restored',
                        'notes' => '[Inventory Restored] Stock returned due to cancellation.',
                    ]);
                    Log::info("Inventory restored on cancel for request {$fulfillmentRequest->id}: qty={$fulfillmentRequest->quantity}");
                }
            }

            $fulfillmentRequest->update([
                'status' => 'cancelled',
                'notes' => ($fulfillmentRequest->notes ? $fulfillmentRequest->notes . "\n" : "") . 'Cancelled: ' . $validated['cancel_reason'],
                'cancelled_by' => 'staff',
            ]);

            // Notify partner
            Notification::create([
                'user_id' => $fulfillmentRequest->partnerCustomer->partner_id,
                'title' => 'Order Cancelled',
                'message' => "Your order #{$fulfillmentRequest->id} has been cancelled. Reason: {$validated['cancel_reason']}",
                'type' => 'fulfillment',
                'related_to_type' => FulfillmentRequest::class,
                'related_to_id' => $fulfillmentRequest->id,
            ]);

            FulfillmentActivityLog::create([
                'fulfillment_request_id' => $fulfillmentRequest->id,
                'user_id' => auth()->id(),
                'action' => 'cancelled',
                'notes' => 'Request cancelled: ' . $validated['cancel_reason'],
            ]);
        });

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerProduct']), 'Request cancelled successfully');
    }

    public function delayRequest(Request $request, $id)
    {
        $this->checkModuleEnabled();
        $validated = $request->validate([
            'delay_reason' => 'required|string',
            'new_delivery_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $fulfillmentRequest = FulfillmentRequest::findOrFail($id);

        if (in_array($fulfillmentRequest->status, ['delivered', 'cancelled'])) {
            return $this->error('Request cannot be put on hold in current status', 400);
        }

        $fulfillmentRequest->update([
            'delay_reason' => $validated['delay_reason'],
            'new_delivery_date' => $validated['new_delivery_date'] ?? null,
            'notes' => $validated['notes'] ?? $fulfillmentRequest->notes,
            'status' => 'pending', // Reverting to pending as requested for hold/resolution
        ]);

        FulfillmentActivityLog::create([
            'fulfillment_request_id' => $fulfillmentRequest->id,
            'user_id' => auth()->id(),
            'action' => 'pending',
            'notes' => 'Order put on pending/hold. Reason: ' . $validated['delay_reason'] . ($validated['notes'] ? ' | Notes: ' . $validated['notes'] : ''),
        ]);

        return $this->success($fulfillmentRequest->load(['partnerCustomer.customer', 'partnerProduct']), 'Delivery status updated to pending successfully');
    }

    public function customerInvoices($customerId)
    {
        $this->checkModuleEnabled();
        $partnerCustomer = PartnerCustomer::findOrFail($customerId);

        $invoices = Invoice::whereHas('fulfillmentRequest', function ($query) use ($partnerCustomer) {
            $query->where('partner_customer_id', $partnerCustomer->id);
        })->with('customer')->orderBy('created_at', 'desc')->paginate(20);

        return $this->success($invoices);
    }

    public function customerPayments($customerId)
    {
        $this->checkModuleEnabled();
        $partnerCustomer = PartnerCustomer::findOrFail($customerId);

        $payments = \App\Models\Payment::whereHas('invoice', function ($query) use ($partnerCustomer) {
            $query->whereHas('fulfillmentRequest', function ($q) use ($partnerCustomer) {
                $q->where('partner_customer_id', $partnerCustomer->id);
            });
        })->with('invoice')->orderBy('payment_date', 'desc')->paginate(20);

        return $this->success($payments);
    }

    public function customerTransactions($customerId)
    {
        $this->checkModuleEnabled();
        $partnerCustomer = PartnerCustomer::findOrFail($customerId);

        $requests = FulfillmentRequest::where('partner_customer_id', $partnerCustomer->id)
            ->with('invoice')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($req) {
                return [
                    'type' => 'fulfillment_request',
                    'id' => $req->id,
                    'status' => $req->status,
                    'quantity' => $req->quantity,
                    'product' => $req->partnerProduct->name ?? null,
                    'delivery_address' => $req->delivery_address,
                    'requested_at' => $req->requested_at,
                    'completed_at' => $req->completed_at,
                    'invoice' => $req->invoice,
                ];
            });

        return $this->success($requests);
    }

    public function staff()
    {
        $this->checkModuleEnabled();
        // Get warehouse staff - users with warehouse_officer role or assigned to partner customers
        $staff = User::whereHas('role', function ($query) {
            $query->whereIn('slug', ['warehouse_officer', 'operations_manager', 'customer_service']);
        })->where('is_active', true)->get();

        // Batch-load customer counts in a single query instead of N+1
        $staffIds = $staff->pluck('id');
        $customerCounts = PartnerCustomer::whereIn('staff_id', $staffIds)
            ->select('staff_id', DB::raw('count(*) as count'))
            ->groupBy('staff_id')
            ->pluck('count', 'staff_id')
            ->toArray();

        $staff = $staff->map(function ($user) use ($customerCounts) {
            $user->assigned_customers = $customerCounts[$user->id] ?? 0;
            return $user;
        });

        return $this->success($staff);
    }

    public function warehouses()
    {
        $this->checkModuleEnabled();
        $warehouses = Warehouse::where('is_active', true)->get();
        return $this->success($warehouses);
    }

    public function availableDispatchers()
    {
        $this->checkModuleEnabled();
        $dispatchers = Dispatcher::where('is_available', true)->with('user')->get();
        return $this->success($dispatchers);
    }

    public function analytics(Request $request)
    {
        try {
            $this->checkModuleEnabled();
        } catch (\Exception $e) {
            return $this->success([
                'stats' => [
                    'total_revenue' => 0,
                    'total_orders' => 0,
                    'pending_orders' => 0,
                    'total_customers' => 0,
                    'active_customers' => 0,
                    'products' => 0,
                    'completion_rate' => 0,
                ],
                'orders_by_status' => [],
                'top_customers' => [],
                'am_performance' => [],
                'monthly_revenue' => [],
                'top_products' => [],
            ]);
        }

        $days = max((int) ($request->days ?? 30), 1);
        $startDate = now()->subDays($days);

        $totalCustomers = PartnerCustomer::count();
        $activeCustomers = PartnerCustomer::where('is_active', true)->count();
        $totalProducts = PartnerProduct::count();

        $ordersQuery = FulfillmentRequest::query()->where('created_at', '>=', $startDate);
        $totalOrders = (clone $ordersQuery)->count();
        $pendingOrders = (clone $ordersQuery)->whereIn('status', ['pending', 'acknowledged', 'assigned', 'in_transit'])->count();
        $completedOrders = (clone $ordersQuery)->where('status', 'delivered')->count();
        $completionRate = $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100) : 0;
        $totalRevenue = (float) ((clone $ordersQuery)
            ->where('status', 'delivered')
            ->sum('delivery_cost') ?? 0);

        $ordersByStatus = FulfillmentRequest::select('status', DB::raw('count(*) as count'))
            ->where('created_at', '>=', $startDate)
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status')
            ->toArray();

        $topCustomers = PartnerCustomer::query()
            ->leftJoin('fulfillment_requests', function ($join) use ($startDate) {
                $join->on('fulfillment_requests.partner_customer_id', '=', 'partner_customers.id')
                    ->where('fulfillment_requests.created_at', '>=', $startDate);
            })
            ->leftJoin('customers', 'customers.id', '=', 'partner_customers.customer_id')
            ->select(
                'partner_customers.id',
                DB::raw('COALESCE(customers.name, "-") as customer_name'),
                DB::raw('count(fulfillment_requests.id) as orders'),
                DB::raw('COALESCE(sum(CASE WHEN fulfillment_requests.status = "delivered" THEN fulfillment_requests.delivery_cost ELSE 0 END), 0) as revenue')
            )
            ->groupBy('partner_customers.id', 'customers.name')
            ->orderByDesc('revenue')
            ->orderByDesc('orders')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                return [
                    'name' => $row->customer_name,
                    'orders' => (int) $row->orders,
                    'revenue' => (float) $row->revenue,
                ];
            });

        // Operations staff handling partner customers.
        $staffIds = User::whereHas('role', function ($query) {
            $query->whereIn('slug', ['operations', 'operations_manager', 'customer_service']);
        })->pluck('id');

        $staffUsers = User::whereIn('id', $staffIds)->with('role')->get()->keyBy('id');

        $customerCounts = PartnerCustomer::whereIn('staff_id', $staffIds)
            ->select('staff_id', DB::raw('count(*) as count'))
            ->groupBy('staff_id')
            ->pluck('count', 'staff_id')
            ->toArray();

        $orderStats = FulfillmentRequest::whereIn('staff_id', $staffIds)
            ->where('created_at', '>=', $startDate)
            ->select(
                'staff_id',
                DB::raw('count(*) as total_orders'),
                DB::raw('SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as completed_orders'),
                DB::raw('SUM(CASE WHEN status = "delivered" THEN delivery_cost ELSE 0 END) as revenue')
            )
            ->groupBy('staff_id')
            ->get()
            ->keyBy('staff_id');

        $amPerformance = $staffIds->map(function ($staffId) use ($staffUsers, $customerCounts, $orderStats) {
            $user = $staffUsers[$staffId] ?? null;
            if (!$user) return null;

            $stats = $orderStats[$staffId] ?? null;
            $ordersCount = $stats ? (int) $stats->total_orders : 0;
            $completedCount = $stats ? (int) $stats->completed_orders : 0;
            $revenue = $stats ? (float) $stats->revenue : 0;

            return [
                'name' => $user->name,
                'role' => $user->role->slug ?? 'operations',
                'customers' => $customerCounts[$staffId] ?? 0,
                'orders' => $ordersCount,
                'revenue' => $revenue,
                'completion_rate' => $ordersCount > 0 ? round(($completedCount / $ordersCount) * 100) : 0,
            ];
        })->filter()->sortByDesc('orders')->values();

        $topProducts = PartnerProduct::query()
            ->leftJoin('fulfillment_requests', function ($join) use ($startDate) {
                $join->on('fulfillment_requests.partner_product_id', '=', 'partner_products.id')
                    ->where('fulfillment_requests.created_at', '>=', $startDate);
            })
            ->select(
                'partner_products.id',
                'partner_products.name',
                DB::raw('COALESCE(sum(fulfillment_requests.quantity), 0) as sold'),
                DB::raw('count(fulfillment_requests.id) as orders')
            )
            ->groupBy('partner_products.id', 'partner_products.name')
            ->orderByDesc('sold')
            ->orderByDesc('orders')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                return [
                    'name' => $row->name ?? '-',
                    'sold' => (int) $row->sold,
                    'orders' => (int) $row->orders,
                ];
            });

        $monthlyRevenue = collect(range(0, 11))->map(function ($i) {
            $monthStart = now()->startOfMonth()->subMonths(11 - $i);
            $monthEnd = (clone $monthStart)->endOfMonth();

            $revenue = (float) FulfillmentRequest::query()
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->where('status', 'delivered')
                ->sum('delivery_cost');

            return [
                'month' => $monthStart->format('M'),
                'revenue' => $revenue,
            ];
        });

        return $this->success([
            'stats' => [
                'total_revenue' => $totalRevenue,
                'total_orders' => $totalOrders,
                'pending_orders' => $pendingOrders,
                'total_customers' => $totalCustomers,
                'active_customers' => $activeCustomers,
                'products' => $totalProducts,
                'completion_rate' => $completionRate,
            ],
            'orders_by_status' => [
                ['name' => 'Pending', 'value' => $ordersByStatus['pending'] ?? 0, 'color' => '#fbbf24'],
                ['name' => 'Processing', 'value' => $ordersByStatus['processing'] ?? 0, 'color' => '#3b82f6'],
                ['name' => 'Acknowledged', 'value' => $ordersByStatus['acknowledged'] ?? 0, 'color' => '#8b5cf6'],
                ['name' => 'In Transit', 'value' => $ordersByStatus['in_transit'] ?? 0, 'color' => '#6366f1'],
                ['name' => 'Delivered', 'value' => $ordersByStatus['delivered'] ?? 0, 'color' => '#22c55e'],
                ['name' => 'Cancelled', 'value' => $ordersByStatus['cancelled'] ?? 0, 'color' => '#ef4444'],
            ],
            'top_customers' => $topCustomers,
            'am_performance' => $amPerformance,
            'monthly_revenue' => $monthlyRevenue,
            'top_products' => $topProducts,
        ]);
    }

    public function staffPerformance(Request $request)
    {
        $period = $request->get('period', 'month');

        $dateRange = $this->getDateRange($period);

        $dispatchers = Dispatcher::where('is_active', true)->get();
        $warehouseStaff = User::whereHas('roles', function ($q) {
            $q->whereIn('slug', ['warehouse_officer', 'warehouse_manager', 'warehouse_staff']);
        })->get();

        $pdStats = DB::table('pickup_deliveries')
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->select(
                'dispatcher_id',
                'type',
                DB::raw('count(*) as total'),
                DB::raw('SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed'),
                DB::raw('SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed')
            )
            ->whereNotNull('dispatcher_id')
            ->groupBy('dispatcher_id', 'type')
            ->get();

        $dispatcherMap = [];
        foreach ($pdStats as $stat) {
            $dispatcherMap[$stat->dispatcher_id][$stat->type] = $stat;
        }

        $dispatcherStats = $dispatchers->map(function ($dispatcher) use ($dispatcherMap) {
            $pickupStat = $dispatcherMap[$dispatcher->id]['pickup'] ?? null;
            $deliveryStat = $dispatcherMap[$dispatcher->id]['delivery'] ?? null;

            $totalPickups = $pickupStat ? $pickupStat->total : 0;
            $totalDeliveries = $deliveryStat ? $deliveryStat->total : 0;
            
            $completedPickups = $pickupStat ? $pickupStat->completed : 0;
            $completedDeliveries = $deliveryStat ? $deliveryStat->completed : 0;
            
            $failedPickups = $pickupStat ? $pickupStat->failed : 0;
            $failedDeliveries = $deliveryStat ? $deliveryStat->failed : 0;

            $total = $totalPickups + $totalDeliveries;
            $completed = $completedPickups + $completedDeliveries;
            $failed = $failedPickups + $failedDeliveries;

            return [
                'id' => $dispatcher->id,
                'name' => $dispatcher->user->name ?? $dispatcher->name,
                'role' => 'Dispatcher',
                'avatar' => substr($dispatcher->user->name ?? $dispatcher->name, 0, 2),
                'vehicle' => $dispatcher->vehicle->name ?? null,
                'total' => $total,
                'completed' => $completed,
                'failed' => $failed,
                'on_time_rate' => $total > 0 ? round(($completed / $total) * 100) : 0,
                'rating' => 4.5,
            ];
        });

        $whStats = DB::table('pickup_deliveries')
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->where('type', 'pickup')
            ->select(
                'created_by',
                DB::raw('count(*) as total'),
                DB::raw('SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed'),
                DB::raw('SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed')
            )
            ->groupBy('created_by')
            ->get()
            ->keyBy('created_by');

        $warehouseStats = $warehouseStaff->map(function ($staff) use ($whStats) {
            $stat = $whStats[$staff->id] ?? null;
            
            $total = $stat ? $stat->total : 0;
            $completed = $stat ? $stat->completed : 0;
            $failed = $stat ? $stat->failed : 0;

            return [
                'id' => $staff->id,
                'name' => $staff->name,
                'role' => 'Warehouse Officer',
                'avatar' => substr($staff->name, 0, 2),
                'total' => $total,
                'completed' => $completed,
                'failed' => $failed,
                'on_time_rate' => $total > 0 ? round(($completed / $total) * 100) : 0,
                'rating' => 4.7,
            ];
        });

        $allStaff = $dispatcherStats->concat($warehouseStats);

        $totalDispatchers = $dispatcherStats->count();
        $totalWarehouse = $warehouseStats->count();
        $avgRating = $allStaff->count() > 0 ? round($allStaff->avg('rating'), 1) : 0;
        $avgOnTime = $allStaff->count() > 0 ? round($allStaff->avg('on_time_rate')) : 0;

        return $this->success([
            'overview' => [
                'total_dispatchers' => $totalDispatchers,
                'total_warehouse' => $totalWarehouse,
                'avg_rating' => $avgRating,
                'avg_on_time' => $avgOnTime,
            ],
            'staff' => $allStaff->values(),
        ]);
    }

    private function getDateRange(string $period): array
    {
        $now = now();
        switch ($period) {
            case 'day':
                return ['start' => $now->startOfDay(), 'end' => $now->endOfDay()];
            case 'week':
                return ['start' => $now->startOfWeek(), 'end' => $now->endOfWeek()];
            case 'month':
                return ['start' => $now->startOfMonth(), 'end' => $now->endOfMonth()];
            case 'quarter':
                return ['start' => $now->startOfQuarter(), 'end' => $now->endOfQuarter()];
            case '6month':
                return ['start' => $now->subMonths(5)->startOfMonth(), 'end' => $now->endOfMonth()];
            case 'year':
                return ['start' => $now->startOfYear(), 'end' => $now->endOfYear()];
            default:
                return ['start' => $now->startOfMonth(), 'end' => $now->endOfMonth()];
        }
    }

    private function notifyAdmins($title, $message, $type, $relatedTo = null)
    {
        $admins = User::whereHas('role', function($q) {
            $q->whereIn('name', ['super_admin', 'operations_manager', 'operations']);
        })->get();

        foreach ($admins as $admin) {
            Notification::create([
                'user_id' => $admin->id,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'related_to_type' => $relatedTo ? get_class($relatedTo) : null,
                'related_to_id' => $relatedTo ? $relatedTo->id : null,
            ]);
        }
    }
}
