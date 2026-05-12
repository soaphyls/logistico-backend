<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        // Accountants can view but not create/edit
        $canEdit = !in_array($role, ['accountant', 'customer_service', 'warehouse_officer', 'dispatcher']);
        
        $query = Customer::with('createdBy');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('customer_code', 'like', "%{$search}%");
            });
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $customers = $query->orderBy('created_at', 'desc')->paginate(20);

        return $this->success($customers);
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if (in_array($role, ['dispatcher', 'warehouse_officer', 'accountant'])) {
            return $this->error('You do not have permission to create customers', 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'required|string|max:20',
            'address' => 'required',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'type' => 'required|in:individual,business',
            'company_name' => 'nullable|string|max:255',
            'status' => 'nullable|in:lead,prospect,customer',
            'source' => 'nullable|string|max:100',
            'lead_score' => 'nullable|integer|min:0|max:100',
            'notes' => 'nullable|string',
        ]);

        $lastCustomer = Customer::latest()->first();
        $sequence = $lastCustomer ? (int) substr($lastCustomer->customer_code, -4) + 1 : 1;
        $validated['customer_code'] = 'CUS-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
        $validated['created_by'] = auth()->id();
        
        if (!isset($validated['status'])) {
            $validated['status'] = 'lead';
        }

        $customer = Customer::create($validated);

        return $this->success($customer, 'Customer created successfully', 201);
    }

    public function show(Customer $customer)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if ($role === 'warehouse_officer' || $role === 'accountant') {
            return $this->error('You do not have permission to view customers', 403);
        }

        $customer->load(['createdBy', 'shipments', 'invoices']);

        $shipmentCount = $customer->shipments()->count();
        $outstandingBalance = $customer->outstanding_balance;

        $customerData = $customer->toArray();
        $customerData['shipment_count'] = $shipmentCount;
        $customerData['outstanding_balance'] = $outstandingBalance;

        return $this->success($customerData);
    }

    public function update(Request $request, Customer $customer)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if (in_array($role, ['dispatcher', 'customer_service', 'warehouse_officer', 'accountant'])) {
            return $this->error('You do not have permission to update customers', 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'sometimes|string|max:20',
            'address' => 'sometimes',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'type' => 'sometimes|in:individual,business',
            'company_name' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string',
        ]);

        $customer->update($validated);

        return $this->success($customer, 'Customer updated successfully');
    }

    public function destroy(Customer $customer)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if (in_array($role, ['dispatcher', 'customer_service', 'warehouse_officer', 'accountant'])) {
            return $this->error('You do not have permission to delete customers', 403);
        }

        if ($customer->shipments()->count() > 0) {
            return $this->error('Cannot delete customer with existing shipments', 400);
        }

        $customer->delete();

        return $this->success(null, 'Customer deleted successfully');
    }

    public function shipments(Request $request, Customer $customer)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if ($role === 'warehouse_officer' || $role === 'accountant') {
            return $this->error('You do not have permission to view customer shipments', 403);
        }

        $shipments = $customer->shipments()
            ->with('customer')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return $this->success($shipments);
    }

    public function invoices(Request $request, Customer $customer)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if ($role === 'warehouse_officer' || $role === 'accountant') {
            return $this->error('You do not have permission to view customer invoices', 403);
        }

        $invoices = $customer->invoices()
            ->with(['payments', 'shipment'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return $this->success($invoices);
    }

    public function statement(Request $request, Customer $customer)
    {
        $user = auth()->user();
        $role = $user->role?->slug;

        if (in_array($role, ['dispatcher', 'customer_service', 'warehouse_officer', 'accountant'])) {
            return $this->error('You do not have permission to view customer statements', 403);
        }

        $invoices = $customer->invoices()
            ->with('payments')
            ->orderBy('created_at', 'desc')
            ->get();

        $statement = $invoices->map(function ($invoice) {
            return [
                'invoice_number' => $invoice->invoice_number,
                'date' => $invoice->created_at->toDateString(),
                'description' => 'Invoice #' . $invoice->invoice_number,
                'debit' => $invoice->total_amount,
                'credit' => $invoice->amount_paid,
                'balance' => $invoice->total_amount - $invoice->amount_paid,
                'status' => $invoice->status,
            ];
        });

        $totalInvoiced = $invoices->sum('total_amount');
        $totalPaid = $invoices->sum(function ($inv) {
            return $inv->payments->sum('amount');
        });

        return $this->success([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'customer_code' => $customer->customer_code,
            ],
            'statement' => $statement,
            'summary' => [
                'total_invoiced' => $totalInvoiced,
                'total_paid' => $totalPaid,
                'outstanding' => $totalInvoiced - $totalPaid,
            ],
        ]);
    }

    public function analytics()
    {
        $totalCustomers = Customer::count();
        
        $leadsCount = Customer::where('status', 'lead')->count();
        $prospectsCount = Customer::where('status', 'prospect')->count();
        $customersCount = Customer::where('status', 'customer')->count();
        
        $activeCustomers = Customer::where('is_active', true)->count();
        $inactiveCustomers = Customer::where('is_active', false)->count();
        
        $avgLeadScore = Customer::where('lead_score', '>', 0)->avg('lead_score') ?? 0;
        
        $conversionRate = $totalCustomers > 0 
            ? round(($customersCount / $totalCustomers) * 100, 1) 
            : 0;
        
        $sourceDistribution = Customer::whereNotNull('source')
            ->selectRaw('source, COUNT(*) as count')
            ->groupBy('source')
            ->pluck('count', 'source');
        
        $totalLeads = $leadsCount + $prospectsCount + $customersCount;
        $leadToProspectRate = $totalLeads > 0 ? round(($prospectsCount / $totalLeads) * 100, 1) : 0;
        $prospectToCustomerRate = $totalLeads > 0 ? round(($customersCount / $totalLeads) * 100, 1) : 0;
        
        $monthlyLeads = Customer::selectRaw('MONTH(created_at) as month, COUNT(*) as count')
            ->whereRaw('YEAR(created_at) = YEAR(NOW())')
            ->groupBy('month')
            ->pluck('count', 'month');
        
        return $this->success([
            'overview' => [
                'total' => $totalCustomers,
                'leads' => $leadsCount,
                'prospects' => $prospectsCount,
                'customers' => $customersCount,
                'active' => $activeCustomers,
                'inactive' => $inactiveCustomers,
            ],
            'funnel' => [
                'leads' => $leadsCount,
                'prospects' => $prospectsCount,
                'customers' => $customersCount,
                'lead_to_prospect_rate' => $leadToProspectRate,
                'prospect_to_customer_rate' => $prospectToCustomerRate,
                'conversion_rate' => $conversionRate,
            ],
            'metrics' => [
                'average_lead_score' => round($avgLeadScore, 1),
                'conversion_rate' => $conversionRate,
            ],
            'sources' => $sourceDistribution,
            'monthly_leads' => $monthlyLeads,
        ]);
    }
}
