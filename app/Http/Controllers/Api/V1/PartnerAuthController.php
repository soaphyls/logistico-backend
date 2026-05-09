<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class PartnerAuthController extends Controller
{
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::with('role')->where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return $this->error('Invalid email or password', 401);
        }

        if (!$user->is_active) {
            return $this->error('Your account has been deactivated. Please contact support.', 403);
        }

        // Check if user is a partner
        if ($user->role?->name !== 'partner') {
            return $this->error('This portal is for partners only. Please use the main login.', 403);
        }

        $token = $user->createToken('partner-token')->plainTextToken;

        return $this->success([
            'user' => $user->load('role'),
            'token' => $token,
        ], 'Login successful');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return $this->success(null, 'Logged out successfully');
    }

    public function me(Request $request)
    {
        return $this->success($request->user()->load(['role']));
    }

    public function orders(Request $request)
    {
        $user = $request->user();

        // Get partner customer IDs for this user
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->pluck('id');

        $query = \App\Models\FulfillmentRequest::with(['partnerCustomer', 'partnerProduct', 'staff', 'dispatcher.user'])
            ->whereIn('partner_customer_id', $partnerCustomerIds);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 20));

        return $this->success($orders);
    }

    public function createOrder(Request $request)
    {
        $validated = $request->validate([
            'customer_name' => 'required|string',
            'customer_phone' => 'required|string',
            'delivery_address' => 'required|string',
            'partner_product_id' => 'required|exists:partner_products,id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();

        // Find partner's customer profile
        $warehouse = \App\Models\Warehouse::first();
        if (!$warehouse) {
            return $this->error('No warehouse available. Please contact support.', 400);
        }

        $partnerCustomer = \App\Models\PartnerCustomer::firstOrCreate(
            ['partner_id' => $user->id],
            [
                'customer_id' => $user->customer_id ?? 1,
                'warehouse_id' => $warehouse->id,
                'staff_id' => $user->id,
                'created_by' => $user->id,
            ]
        );

        // Get the selected product
        $product = \App\Models\PartnerProduct::where('id', $validated['partner_product_id'])
            ->where('partner_customer_id', $partnerCustomer->id)
            ->firstOrFail();

        if ($product->quantity < $validated['quantity']) {
            return $this->error('Insufficient stock. Available: ' . $product->quantity, 400);
        }

        // Calculate COD from product cost
        $codAmount = ($product->unit_cost ?? 0) * $validated['quantity'];

        // Create fulfillment request
        $fulfillmentRequest = \App\Models\FulfillmentRequest::create([
            'partner_customer_id' => $partnerCustomer->id,
            'partner_product_id' => $product->id,
            'staff_id' => $partnerCustomer->staff_id,
            'quantity' => $validated['quantity'],
            'delivery_address' => $validated['delivery_address'],
            'delivery_phone' => $validated['customer_phone'],
            'delivery_notes' => $validated['customer_name'],
            'status' => 'pending',
            'requested_by' => $user->name,
            'requested_at' => now(),
            'notes' => $validated['notes'] ?? null,
            'cod_amount' => $codAmount,
            'remittance_amount' => $codAmount, // delivery_cost is 0 initially
        ]);

        // Log activity
        \App\Models\FulfillmentActivityLog::create([
            'fulfillment_request_id' => $fulfillmentRequest->id,
            'user_id' => $user->id,
            'action' => 'created',
            'notes' => 'Order created by partner: ' . $user->name,
        ]);

        return $this->success(
            $fulfillmentRequest->load(['partnerCustomer', 'partnerProduct', 'staff']),
            'Order created successfully',
            201
        );
    }

    public function showOrder(Request $request, $id)
    {
        $user = $request->user();
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->pluck('id');

        $order = \App\Models\FulfillmentRequest::with(['partnerCustomer', 'partnerProduct', 'staff', 'dispatcher.user', 'dispatcher.user'])
            ->where('id', $id)
            ->whereIn('partner_customer_id', $partnerCustomerIds)
            ->firstOrFail();

        return $this->success($order);
    }

    public function inventory(Request $request)
    {
        $user = $request->user();
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->pluck('id');

        $products = \App\Models\PartnerProduct::whereIn('partner_customer_id', $partnerCustomerIds)->get();

        return $this->success($products);
    }

    public function addInventory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:100|unique:partner_products,sku',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0',
            'reorder_level' => 'required|integer|min:0',
            'description' => 'nullable|string',
        ]);

        $user = $request->user();

        // Find first available warehouse
        $warehouse = \App\Models\Warehouse::first();
        if (!$warehouse) {
            return $this->error('No warehouse available. Please contact support.', 400);
        }

        // Find or create partner customer for this partner
        $partnerCustomer = \App\Models\PartnerCustomer::firstOrCreate(
            ['partner_id' => $user->id],
            [
                'customer_id' => $user->customer_id ?? 1,
                'warehouse_id' => $warehouse->id,
                'staff_id' => $user->id,
                'created_by' => $user->id,
            ]
        );

        $product = \App\Models\PartnerProduct::create([
            'partner_customer_id' => $partnerCustomer->id,
            'sku' => $validated['sku'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'quantity' => $validated['quantity'],
            'reorder_level' => $validated['reorder_level'],
            'unit_cost' => $validated['price'],
            'is_active' => true,
        ]);

        return $this->success($product, 'Product added successfully', 201);
    }

    public function cancelOrder(Request $request, $id)
    {
        $user = $request->user();
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->pluck('id');

        $order = \App\Models\FulfillmentRequest::where('id', $id)
            ->whereIn('partner_customer_id', $partnerCustomerIds)
            ->firstOrFail();

        if ($order->status !== 'pending') {
            return $this->error('Only pending orders can be cancelled', 400);
        }

        $order->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancel_reason' => 'Cancelled by partner',
            'cancelled_by' => $user->name,
        ]);

        // Log activity
        \App\Models\FulfillmentActivityLog::create([
            'fulfillment_request_id' => $order->id,
            'user_id' => $user->id,
            'action' => 'cancelled',
            'notes' => 'Order cancelled by partner: ' . $user->name,
        ]);

        return $this->success($order, 'Order cancelled successfully');
    }

    public function respondToFailure(Request $request, $id)
    {
        $validated = $request->validate([
            'response' => 'required|string|max:1000',
            'new_address' => 'nullable|string|max:500',
            'new_phone' => 'nullable|string|max:20',
        ]);

        $user = $request->user();
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->pluck('id');

        $order = \App\Models\FulfillmentRequest::where('id', $id)
            ->whereIn('partner_customer_id', $partnerCustomerIds)
            ->firstOrFail();

        if ($order->status !== 'failed') {
            return $this->error('Can only respond to failed orders', 400);
        }

        $updateData = [
            'partner_response' => $validated['response'],
        ];

        if (!empty($validated['new_address'])) {
            $updateData['new_delivery_address'] = $validated['new_address'];
        }

        if (!empty($validated['new_phone'])) {
            $updateData['new_delivery_phone'] = $validated['new_phone'];
        }

        $order->update($updateData);

        // Log activity
        \App\Models\FulfillmentActivityLog::create([
            'fulfillment_request_id' => $order->id,
            'user_id' => $user->id,
            'action' => 'partner_responded',
            'notes' => 'Partner responded: ' . $validated['response'],
        ]);

        return $this->success($order->fresh(), 'Response submitted successfully');
    }

    public function acceptOrder($id)
    {
        $user = auth()->user();
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->pluck('id');

        $order = \App\Models\FulfillmentRequest::where('id', $id)
            ->whereIn('partner_customer_id', $partnerCustomerIds)
            ->firstOrFail();

        if ($order->status !== 'awaiting_partner_action' && $order->status !== 'rejected') {
            return $this->error('Order is not awaiting partner action', 400);
        }

        $order->update([
            'status' => 'accepted',
        ]);

        \App\Models\FulfillmentActivityLog::create([
            'fulfillment_request_id' => $order->id,
            'user_id' => $user->id,
            'action' => 'accepted',
            'notes' => 'Partner accepted the delivery cost via portal',
        ]);

        return $this->success($order, 'Order accepted successfully');
    }

    public function rejectOrder(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        $user = auth()->user();
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->pluck('id');

        $order = \App\Models\FulfillmentRequest::where('id', $id)
            ->whereIn('partner_customer_id', $partnerCustomerIds)
            ->firstOrFail();

        if ($order->status !== 'awaiting_partner_action') {
            return $this->error('Order is not awaiting partner action', 400);
        }

        $order->update([
            'status' => 'rejected',
            'partner_response' => $validated['reason'],
        ]);

        \App\Models\FulfillmentActivityLog::create([
            'fulfillment_request_id' => $order->id,
            'user_id' => $user->id,
            'action' => 'rejected',
            'notes' => 'Partner rejected: ' . $validated['reason'],
        ]);

        return $this->success($order, 'Order rejected');
    }

    public function counterOfferOrder(Request $request, $id)
    {
        $validated = $request->validate([
            'counter_amount' => 'required|numeric|min:0',
            'reason' => 'nullable|string',
        ]);

        $user = auth()->user();
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->pluck('id');

        $order = \App\Models\FulfillmentRequest::where('id', $id)
            ->whereIn('partner_customer_id', $partnerCustomerIds)
            ->firstOrFail();

        if (!in_array($order->status, ['awaiting_partner_action', 'rejected'])) {
            return $this->error('Counter offer not allowed for current status', 400);
        }

        $order->update([
            'status' => 'pending',
            'partner_response' => 'Counter offer: ₦' . number_format($validated['counter_amount'], 2) . ($validated['reason'] ? ' — ' . $validated['reason'] : ''),
        ]);

        \App\Models\FulfillmentActivityLog::create([
            'fulfillment_request_id' => $order->id,
            'user_id' => $user->id,
            'action' => 'counter_offer',
            'notes' => 'Partner counter offer: ₦' . number_format($validated['counter_amount'], 2) . ($validated['reason'] ? ' — ' . $validated['reason'] : ''),
        ]);

        return $this->success($order, 'Counter offer submitted. Admin will review your proposed cost.');
    }

    public function billingSummary(Request $request)
    {
        $user = $request->user();
        
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->pluck('id');
            
        $invoiceIdsFromRequests = \App\Models\FulfillmentRequest::whereIn('partner_customer_id', $partnerCustomerIds)
            ->whereNotNull('invoice_id')
            ->pluck('invoice_id');
            
        // Get customer associated with this user
        $customerId = $user->customer_id;
        
        $query = \App\Models\Invoice::query();
        
        if ($customerId) {
            $query->where(function($q) use ($customerId, $invoiceIdsFromRequests) {
                $q->where('customer_id', $customerId)
                  ->orWhereIn('id', $invoiceIdsFromRequests);
            });
        } else {
            $query->whereIn('id', $invoiceIdsFromRequests);
        }
        
        $invoices = $query->get();
        
        $overdueAmount = $invoices->where('status', 'overdue')->sum('total_amount');
        $paidTotals = $invoices->where('status', 'paid')->sum('total_amount');
        $unpaidTotals = $invoices->whereIn('status', ['sent', 'partial'])->sum('total_amount');
        
        $counts = [
            'all' => $invoices->count(),
            'paid' => $invoices->where('status', 'paid')->count(),
            'overdue' => $invoices->where('status', 'overdue')->count(),
            'pending' => $invoices->whereIn('status', ['sent', 'partial'])->count(),
            'draft' => $invoices->where('status', 'draft')->count(),
        ];
        
        return $this->success([
            'overdue_amount' => $overdueAmount,
            'paid_totals' => $paidTotals,
            'unpaid_totals' => $unpaidTotals,
            'counts' => $counts,
        ]);
    }

    public function invoices(Request $request)
    {
        $user = $request->user();
        
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->pluck('id');
            
        $invoiceIdsFromRequests = \App\Models\FulfillmentRequest::whereIn('partner_customer_id', $partnerCustomerIds)
            ->whereNotNull('invoice_id')
            ->pluck('invoice_id');
            
        $customerId = $user->customer_id;
        
        $query = \App\Models\Invoice::with(['customer', 'items']);
        
        if ($customerId) {
            $query->where(function($q) use ($customerId, $invoiceIdsFromRequests) {
                $q->where('customer_id', $customerId)
                  ->orWhereIn('id', $invoiceIdsFromRequests);
            });
        } else {
            $query->whereIn('id', $invoiceIdsFromRequests);
        }
        
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhereHas('customer', function($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }
        
        $invoices = $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 20));
        
        return $this->success($invoices);
    }

    public function reconciliationSummary(Request $request)
    {
        $user = $request->user();
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->pluck('id');
            
        $query = \App\Models\FulfillmentRequest::whereIn('partner_customer_id', $partnerCustomerIds)
            ->where('status', 'delivered');

        if ($request->start_date) {
            $query->whereDate('completed_at', '>=', $request->start_date);
        }
        if ($request->end_date) {
            $query->whereDate('completed_at', '<=', $request->end_date);
        }

        $deliveredOrders = $query->get();
        
        $totalCodCollected = $deliveredOrders->sum(fn($o) => $o->amount_collected ?? $o->cod_amount ?? 0);
        $totalFees = $deliveredOrders->sum('delivery_cost');
        $totalRevenue = $totalCodCollected - $totalFees;
        
        // Pending balance is only for orders that are NOT settled yet
        $pendingOrders = $deliveredOrders->where('remittance_status', 'pending');
        $pendingCod = $pendingOrders->sum(fn($o) => $o->amount_collected ?? $o->cod_amount ?? 0);
        $pendingFees = $pendingOrders->sum('delivery_cost');
        $netBalance = $pendingCod - $pendingFees;
        
        $counts = [
            'total_delivered' => $deliveredOrders->count(),
            'pending_remittance' => $pendingOrders->count(),
            'settled' => $deliveredOrders->where('remittance_status', 'settled')->count(),
            'disputed' => $deliveredOrders->where('remittance_status', 'disputed')->count(),
        ];

        return $this->success([
            'total_cod_collected' => $totalCodCollected,
            'total_delivery_fees' => $totalFees,
            'total_revenue' => $totalRevenue,
            'net_balance' => $netBalance,
            'counts' => $counts,
        ]);
    }

    public function reconciliationOrders(Request $request)
    {
        $user = $request->user();
        
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->pluck('id');
            
        $query = \App\Models\FulfillmentRequest::with(['partnerProduct', 'partnerCustomer'])
            ->whereIn('partner_customer_id', $partnerCustomerIds)
            ->whereIn('status', ['delivered', 'failed', 'cancelled']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('remittance_status')) {
            $query->where('remittance_status', $request->remittance_status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('request_number', 'like', "%{$search}%")
                  ->orWhere('delivery_address', 'like', "%{$search}%")
                  ->orWhere('delivery_phone', 'like', "%{$search}%");
            });
        }

        if ($request->start_date) {
            $query->whereDate('completed_at', '>=', $request->start_date);
        }
        if ($request->end_date) {
            $query->whereDate('completed_at', '<=', $request->end_date);
        }

        $orders = $query->orderBy('completed_at', 'desc')->paginate($request->input('per_page', 20));

        return $this->success($orders);
    }

    public function reconciliationStatement(Request $request)
    {
        $user = $request->user();
        
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->pluck('id');

        $query = \App\Models\FulfillmentRequest::with(['partnerProduct'])
            ->whereIn('partner_customer_id', $partnerCustomerIds)
            ->where('status', 'delivered');

        if ($request->start_date) {
            $query->whereDate('completed_at', '>=', $request->start_date);
        }
        if ($request->end_date) {
            $query->whereDate('completed_at', '<=', $request->end_date);
        }

        $orders = $query->get();

        $totalCollected = $orders->sum(fn($o) => $o->amount_collected ?? $o->cod_amount ?? 0);
        $totalFees = $orders->sum('delivery_cost');
        $totalRemittance = $totalCollected - $totalFees;

        return $this->success([
            'partner_name' => $user->company ?: $user->name,
            'total_deliveries' => $orders->count(),
            'total_collected' => $totalCollected,
            'total_fees' => $totalFees,
            'net_remittance' => $totalRemittance,
            'deliveries' => $orders->map(function ($order) {
                $collected = $order->amount_collected ?? $order->cod_amount ?? 0;
                return [
                    'request_number' => $order->request_number,
                    'product' => $order->partnerProduct?->name,
                    'cod_amount' => $order->cod_amount,
                    'amount_collected' => $collected,
                    'delivery_cost' => $order->delivery_cost,
                    'net_amount' => $collected - $order->delivery_cost,
                    'completed_at' => $order->completed_at?->toIso8601String(),
                ];
            }),
        ]);
    }

    public function raiseDispute(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:fulfillment_requests,id',
            'reason' => 'required|string|max:1000',
        ]);

        $user = $request->user();
        
        $order = \App\Models\FulfillmentRequest::where('id', $validated['order_id'])
            ->whereHas('partnerCustomer', function($q) use ($user) {
                $q->where('partner_id', $user->id);
            })
            ->firstOrFail();

        $order->update([
            'remittance_status' => 'disputed',
            'dispute_note' => $validated['reason'],
        ]);

        \App\Models\FulfillmentActivityLog::create([
            'fulfillment_request_id' => $order->id,
            'user_id' => $user->id,
            'action' => 'disputed',
            'notes' => 'Partner raised dispute: ' . $validated['reason'],
        ]);

        return $this->success($order, 'Dispute raised successfully. Admin will review your request.');
    }
}
