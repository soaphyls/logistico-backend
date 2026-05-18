<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Traits\PartnerModuleTrait;
use App\Models\PartnerCustomer;
use App\Models\PartnerProduct;
use App\Models\FulfillmentRequest;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerCustomerController extends Controller
{
    use PartnerModuleTrait;

    public function index(Request $request)
    {
        $this->checkModuleEnabled();

        $user = auth()->user();

        $query = PartnerCustomer::with(['customer', 'partner', 'warehouse', 'staff', 'products', 'fulfillmentRequests']);

        // Staff (except admins and operations) sees only their assigned customers
        if ($user->role && !in_array($user->role->slug, ['super_admin', 'operations_manager', 'operations'])) {
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

    public function store(Request $request)
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

    public function show($id)
    {
        $this->checkModuleEnabled();

        $partnerCustomer = PartnerCustomer::with(['customer', 'partner', 'warehouse', 'staff', 'products', 'fulfillmentRequests'])->findOrFail($id);
        return $this->success($partnerCustomer);
    }

    public function update(Request $request, $id)
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

    public function destroy($id)
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

    public function invoices($customerId)
    {
        $this->checkModuleEnabled();
        $partnerCustomer = PartnerCustomer::findOrFail($customerId);

        $invoices = Invoice::whereHas('fulfillmentRequest', function ($query) use ($partnerCustomer) {
            $query->where('partner_customer_id', $partnerCustomer->id);
        })->with('customer')->orderBy('created_at', 'desc')->paginate(20);

        return $this->success($invoices);
    }

    public function payments($customerId)
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

    public function transactions($customerId)
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
}
