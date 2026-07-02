<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Notification;
use App\Models\FulfillmentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        // Check if user is a partner or partner staff
        if (!in_array($user->role?->slug, ['partner', 'partner-staff', 'partner_staff'])) {
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
        $ownerId = $user->getPartnerOwnerId();

        // Get partner customer IDs for this user
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $ownerId)
            ->orWhere('created_by', $ownerId)
            ->pluck('id');

        $query = \App\Models\FulfillmentRequest::with(['partnerCustomer', 'partnerProduct', 'staff', 'dispatcher.user'])
            ->whereIn('partner_customer_id', $partnerCustomerIds);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('requested_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('requested_at', '<=', $request->end_date);
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
            'items' => 'nullable|array',
            'items.*.partner_product_id' => 'required_with:items|exists:partner_products,id',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'partner_product_id' => 'required_without:items|exists:partner_products,id',
            'quantity' => 'required_without:items|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        $ownerId = $user->getPartnerOwnerId();

        // Find partner's customer profile
        $warehouse = \App\Models\Warehouse::first();
        if (!$warehouse) {
            return $this->error('No warehouse available. Please contact support.', 400);
        }

        $partnerCustomer = \App\Models\PartnerCustomer::firstOrCreate(
            ['partner_id' => $ownerId],
            [
                'customer_id' => null,
                'warehouse_id' => $warehouse->id,
                'staff_id' => $ownerId,
                'created_by' => $ownerId,
            ]
        );

        // Normalize to items array (backward compat)
        $items = $validated['items'] ?? [
            ['partner_product_id' => $validated['partner_product_id'], 'quantity' => $validated['quantity']],
        ];

        try {
            $createdRequests = DB::transaction(function () use ($items, $validated, $user, $partnerCustomer) {
                $created = [];
                $totalCod = 0;

                foreach ($items as $item) {
                    $product = \App\Models\PartnerProduct::where('id', $item['partner_product_id'])
                        ->where('partner_customer_id', $partnerCustomer->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($product->quantity < $item['quantity']) {
                        throw new \Exception(
                            'Insufficient stock for "' . $product->name . '". Available: ' . $product->quantity,
                            422
                        );
                    }

                    $codAmount = ($product->unit_cost ?? 0) * $item['quantity'];
                    $totalCod += $codAmount;

                    $requestModel = \App\Models\FulfillmentRequest::create([
                        'partner_customer_id' => $partnerCustomer->id,
                        'partner_product_id' => $product->id,
                        'staff_id' => $partnerCustomer->staff_id,
                        'quantity' => $item['quantity'],
                        'delivery_address' => $validated['delivery_address'],
                        'delivery_phone' => $validated['customer_phone'],
                        'delivery_notes' => $validated['customer_name'],
                        'status' => 'pending',
                        'requested_by' => $user->name,
                        'requested_at' => now(),
                        'notes' => $validated['notes'] ?? null,
                        'cod_amount' => $codAmount,
                        'remittance_amount' => $codAmount,
                    ]);

                    $product->decrement('quantity', $item['quantity']);

                    \App\Models\FulfillmentActivityLog::create([
                        'fulfillment_request_id' => $requestModel->id,
                        'user_id' => $user->id,
                        'action' => 'created',
                        'notes' => 'Order created by partner staff: ' . $user->name,
                    ]);

                    $created[] = $requestModel;
                }

                return $created;
            });
        } catch (\Exception $e) {
            $statusCode = $e->getCode() === 422 ? 422 : 400;
            return $this->error($e->getMessage(), $statusCode);
        }

        $firstRequest = $createdRequests[0] ?? null;
        if (!$firstRequest) {
            return $this->error('Failed to create order', 400);
        }

        return $this->success(
            count($createdRequests) === 1
                ? $firstRequest->load(['partnerCustomer', 'partnerProduct', 'staff'])
                : array_map(fn($r) => $r->load(['partnerCustomer', 'partnerProduct', 'staff']), $createdRequests),
            count($createdRequests) . ' order(s) created successfully',
            201
        );
    }

    public function showOrder(Request $request, $id)
    {
        $user = $request->user();
        $ownerId = $user->getPartnerOwnerId();
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $ownerId)
            ->orWhere('created_by', $ownerId)
            ->pluck('id');

        $order = \App\Models\FulfillmentRequest::with([
            'partnerCustomer',
            'partnerProduct',
            'staff',
            'dispatcher.user',
            'activities.user',
        ])
            ->where('id', $id)
            ->whereIn('partner_customer_id', $partnerCustomerIds)
            ->firstOrFail();

        return $this->success($order);
    }

    public function inventory(Request $request)
    {
        $user = $request->user();
        $ownerId = $user->getPartnerOwnerId();
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $ownerId)
            ->orWhere('created_by', $ownerId)
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
        $ownerId = $user->getPartnerOwnerId();

        // Find first available warehouse
        $warehouse = \App\Models\Warehouse::first();
        if (!$warehouse) {
            return $this->error('No warehouse available. Please contact support.', 400);
        }

        // Find or create partner customer for this partner
        $partnerCustomer = \App\Models\PartnerCustomer::firstOrCreate(
            ['partner_id' => $ownerId],
            [
                'customer_id' => null,
                'warehouse_id' => $warehouse->id,
                'staff_id' => $ownerId,
                'created_by' => $ownerId,
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
            'is_approved' => false,
        ]);

        // Notify admins about new product submission (same as main storeProduct flow)
        $adminRoles = ['super_admin', 'operations_manager', 'operations'];
        $admins = \App\Models\User::where(function ($query) use ($adminRoles) {
            $query->whereHas('role', function ($q) use ($adminRoles) {
                $q->whereIn('name', $adminRoles);
            })->orWhereHas('roles', function ($q) use ($adminRoles) {
                $q->whereIn('name', $adminRoles);
            });
        })->get();

        foreach ($admins as $admin) {
            \App\Models\Notification::create([
                'user_id' => $admin->id,
                'title' => 'New Product Submitted',
                'message' => "A new product '{$product->name}' has been submitted for approval.",
                'type' => 'product',
                'related_to_type' => \App\Models\PartnerProduct::class,
                'related_to_id' => $product->id,
            ]);
        }

        return $this->success($product, 'Product added successfully', 201);
    }

    public function bulkAddInventory(Request $request)
    {
        $validated = $request->validate([
            'products' => 'required|array|min:1',
            'products.*.name' => 'required|string|max:255',
            'products.*.sku' => 'required|string|max:100',
            'products.*.price' => 'required|numeric|min:0',
            'products.*.quantity' => 'required|integer|min:0',
            'products.*.reorder_level' => 'required|integer|min:0',
            'products.*.description' => 'nullable|string',
        ]);

        $user = $request->user();
        $ownerId = $user->getPartnerOwnerId();

        $warehouse = \App\Models\Warehouse::first();
        if (!$warehouse) {
            return $this->error('No warehouse available. Please contact support.', 400);
        }

        $partnerCustomer = \App\Models\PartnerCustomer::firstOrCreate(
            ['partner_id' => $ownerId],
            [
                'customer_id' => null,
                'warehouse_id' => $warehouse->id,
                'staff_id' => $ownerId,
                'created_by' => $ownerId,
            ]
        );

        $created = [];
        $errors = [];

        foreach ($validated['products'] as $idx => $item) {
            if (\App\Models\PartnerProduct::where('sku', $item['sku'])->exists()) {
                $errors[] = "Row " . ($idx + 1) . ": SKU '{$item['sku']}' already exists";
                continue;
            }

            $product = \App\Models\PartnerProduct::create([
                'partner_customer_id' => $partnerCustomer->id,
                'sku' => $item['sku'],
                'name' => $item['name'],
                'description' => $item['description'] ?? null,
                'quantity' => $item['quantity'],
                'reorder_level' => $item['reorder_level'],
                'unit_cost' => $item['price'],
                'is_active' => true,
                'is_approved' => false,
            ]);

            \App\Models\Notification::create([
                'user_id' => $user->id,
                'title' => 'New Product Submitted',
                'message' => "A new product '{$product->name}' has been submitted for approval.",
                'type' => 'product',
                'related_to_type' => \App\Models\PartnerProduct::class,
                'related_to_id' => $product->id,
            ]);

            $created[] = $product;
        }

        // Notify admins once for the batch
        if (!empty($created)) {
            $adminRoles = ['super_admin', 'operations_manager', 'operations'];
            $admins = \App\Models\User::where(function ($query) use ($adminRoles) {
                $query->whereHas('role', function ($q) use ($adminRoles) {
                    $q->whereIn('name', $adminRoles);
                })->orWhereHas('roles', function ($q) use ($adminRoles) {
                    $q->whereIn('name', $adminRoles);
                });
            })->get();

            foreach ($admins as $admin) {
                \App\Models\Notification::create([
                    'user_id' => $admin->id,
                    'title' => count($created) . ' New Products Submitted',
                    'message' => count($created) . ' product(s) have been submitted for approval by ' . ($user->name ?? 'a partner') . '.',
                    'type' => 'product',
                ]);
            }
        }

        return $this->success([
            'created' => $created,
            'errors' => $errors,
            'total_created' => count($created),
            'total_errors' => count($errors),
        ], count($created) . ' product(s) created, ' . count($errors) . ' error(s)', 201);
    }

    public function inventoryCsvTemplate(Request $request)
    {
        $headers = ['name', 'sku', 'price', 'quantity', 'reorder_level', 'description'];
        $sample = ['My Product', 'SKU-001', '29.99', '50', '10', 'Optional description'];

        $callback = function () use ($headers, $sample) {
            $fh = fopen('php://output', 'w');
            fprintf($fh, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel
            fputcsv($fh, $headers);
            fputcsv($fh, $sample);
            fclose($fh);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="product-import-template.csv"',
        ]);
    }

    public function uploadInventoryCsv(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:5120',
        ]);

        $user = $request->user();
        $ownerId = $user->getPartnerOwnerId();

        $warehouse = \App\Models\Warehouse::first();
        if (!$warehouse) {
            return $this->error('No warehouse available. Please contact support.', 400);
        }

        $partnerCustomer = \App\Models\PartnerCustomer::firstOrCreate(
            ['partner_id' => $ownerId],
            [
                'customer_id' => null,
                'warehouse_id' => $warehouse->id,
                'staff_id' => $ownerId,
                'created_by' => $ownerId,
            ]
        );

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        // Parse rows from CSV or Excel
        if (in_array($extension, ['xlsx', 'xls'])) {
            $rows = $this->parseExcelFile($file);
        } else {
            $rows = $this->parseCsvFile($file);
        }

        if (count($rows) < 2) {
            return $this->error('File must have a header row and at least one data row', 422);
        }

        $header = array_map('trim', $rows[0]);
        $expected = ['name', 'sku'];
        $missing = array_diff($expected, $header);
        if (!empty($missing)) {
            return $this->error('Missing required columns: ' . implode(', ', $missing), 422);
        }

        $created = [];
        $errors = [];

        for ($i = 1; $i < count($rows); $i++) {
            $data = [];
            foreach ($header as $colIdx => $colName) {
                $data[$colName] = isset($rows[$i][$colIdx]) ? trim((string) $rows[$i][$colIdx]) : '';
            }
            $rowNum = $i + 1;

            if (empty($data['name']) || empty($data['sku'])) {
                $errors[] = "Row {$rowNum}: name and sku are required";
                continue;
            }

            if (\App\Models\PartnerProduct::where('sku', $data['sku'])->exists()) {
                $errors[] = "Row {$rowNum}: SKU '{$data['sku']}' already exists";
                continue;
            }

            $product = \App\Models\PartnerProduct::create([
                'partner_customer_id' => $partnerCustomer->id,
                'sku' => $data['sku'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'quantity' => max(0, (int)($data['quantity'] ?? 0)),
                'reorder_level' => max(0, (int)($data['reorder_level'] ?? 10)),
                'unit_cost' => max(0, (float)($data['price'] ?? 0)),
                'is_active' => true,
                'is_approved' => false,
            ]);

            \App\Models\Notification::create([
                'user_id' => $user->id,
                'title' => 'New Product Submitted',
                'message' => "A new product '{$product->name}' has been submitted for approval.",
                'type' => 'product',
                'related_to_type' => \App\Models\PartnerProduct::class,
                'related_to_id' => $product->id,
            ]);

            $created[] = $product;
        }

        if (!empty($created)) {
            $adminRoles = ['super_admin', 'operations_manager', 'operations'];
            $admins = \App\Models\User::where(function ($query) use ($adminRoles) {
                $query->whereHas('role', function ($q) use ($adminRoles) {
                    $q->whereIn('name', $adminRoles);
                })->orWhereHas('roles', function ($q) use ($adminRoles) {
                    $q->whereIn('name', $adminRoles);
                });
            })->get();

            foreach ($admins as $admin) {
                \App\Models\Notification::create([
                    'user_id' => $admin->id,
                    'title' => count($created) . ' New Products Submitted',
                    'message' => count($created) . ' product(s) have been submitted for approval via upload by ' . ($user->name ?? 'a partner') . '.',
                    'type' => 'product',
                ]);
            }
        }

        return $this->success([
            'created' => $created,
            'errors' => $errors,
            'total_created' => count($created),
            'total_errors' => count($errors),
        ], count($created) . ' product(s) created, ' . count($errors) . ' error(s)', 201);
    }

    private function parseCsvFile($file): array
    {
        $content = file_get_contents($file->getRealPath());

        // Strip UTF-8 BOM if present
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        $lines = array_filter($lines, fn($l) => trim($l) !== '');
        $lines = array_values($lines);

        return array_map('str_getcsv', $lines);
    }

    private function parseExcelFile($file): array
    {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
            $worksheet = $spreadsheet->getActiveSheet();
            $data = $worksheet->toArray();
            // Filter completely empty trailing rows
            while (!empty($data) && count(array_filter($data[count($data) - 1], fn($v) => $v !== null && $v !== '')) === 0) {
                array_pop($data);
            }
            return $data;
        } catch (\Exception $e) {
            throw new \Exception('Failed to parse Excel file: ' . $e->getMessage());
        }
    }

    public function cancelOrder(Request $request, $id)
    {
        $user = $request->user();
        $ownerId = $user->getPartnerOwnerId();
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $ownerId)
            ->orWhere('created_by', $ownerId)
            ->pluck('id');

        $order = \App\Models\FulfillmentRequest::where('id', $id)
            ->whereIn('partner_customer_id', $partnerCustomerIds)
            ->firstOrFail();

        if ($order->status !== 'pending') {
            return $this->error('Only pending orders can be cancelled', 400);
        }

        DB::transaction(function () use ($order, $user) {
            $order->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancel_reason' => 'Cancelled by partner',
                'cancelled_by' => $user->name,
            ]);

            // Restore inventory with row lock
            if ($order->partnerProduct) {
                $product = \App\Models\PartnerProduct::where('id', $order->partner_product_id)
                    ->lockForUpdate()
                    ->first();
                if ($product) {
                    $product->increment('quantity', $order->quantity ?? 1);
                }
            }

            // Log activity
            \App\Models\FulfillmentActivityLog::create([
                'fulfillment_request_id' => $order->id,
                'user_id' => $user->id,
                'action' => 'cancelled',
                'notes' => 'Order cancelled by partner staff: ' . $user->name,
            ]);
        });

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
        $ownerId = $user->getPartnerOwnerId();
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $ownerId)
            ->orWhere('created_by', $ownerId)
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
        $ownerId = $user->getPartnerOwnerId();
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $ownerId)
            ->orWhere('created_by', $ownerId)
            ->pluck('id');

        $order = \App\Models\FulfillmentRequest::where('id', $id)
            ->whereIn('partner_customer_id', $partnerCustomerIds)
            ->firstOrFail();

        if ($order->status !== 'awaiting_partner_action' && $order->status !== 'rejected') {
            return $this->error('Order is not awaiting partner action', 400);
        }

        DB::transaction(function () use ($order, $user) {
            $order->update([
                'status' => 'accepted',
            ]);

            \App\Models\FulfillmentActivityLog::create([
                'fulfillment_request_id' => $order->id,
                'user_id' => $user->id,
                'action' => 'accepted',
                'notes' => 'Partner accepted the delivery cost via portal',
            ]);
        });

        dispatch(function () use ($order) {
            $this->notifyAdmins(
                'Order Cost Accepted',
                "Partner accepted cost for #{$order->request_number}",
                'fulfillment',
                $order
            );
        })->afterResponse();

        return $this->success($order, 'Order accepted successfully');
    }

    public function rejectOrder(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        $user = auth()->user();
        $ownerId = $user->getPartnerOwnerId();
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $ownerId)
            ->orWhere('created_by', $ownerId)
            ->pluck('id');

        $order = \App\Models\FulfillmentRequest::where('id', $id)
            ->whereIn('partner_customer_id', $partnerCustomerIds)
            ->firstOrFail();

        if ($order->status !== 'awaiting_partner_action') {
            return $this->error('Order is not awaiting partner action', 400);
        }

        DB::transaction(function () use ($order, $validated, $user) {
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
        });

        dispatch(function () use ($order, $validated) {
            $this->notifyAdmins(
                'Order Cost Rejected',
                "Partner rejected cost for #{$order->request_number}. Reason: {$validated['reason']}",
                'fulfillment',
                $order
            );
        })->afterResponse();

        return $this->success($order, 'Order rejected');
    }

    public function counterOfferOrder(Request $request, $id)
    {
        $validated = $request->validate([
            'counter_amount' => 'required|numeric|min:0',
            'reason' => 'nullable|string',
        ]);

        $user = auth()->user();
        $ownerId = $user->getPartnerOwnerId();
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $ownerId)
            ->orWhere('created_by', $ownerId)
            ->pluck('id');

        $order = \App\Models\FulfillmentRequest::where('id', $id)
            ->whereIn('partner_customer_id', $partnerCustomerIds)
            ->firstOrFail();

        if (!in_array($order->status, ['awaiting_partner_action', 'rejected'])) {
            return $this->error('Counter offer not allowed for current status', 400);
        }

        DB::transaction(function () use ($order, $validated, $user) {
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
        });

        dispatch(function () use ($order, $validated) {
            $this->notifyAdmins(
                'New Counter Offer',
                "Partner submitted counter offer for #{$order->request_number}: ₦" . number_format($validated['counter_amount'], 2),
                'fulfillment',
                $order
            );
        })->afterResponse();

        return $this->success($order, 'Counter offer submitted. Admin will review your proposed cost.');
    }

    public function billingSummary(Request $request)
    {
        $user = $request->user();
        $ownerId = $user->getPartnerOwnerId();
        
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $ownerId)
            ->orWhere('created_by', $ownerId)
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
        $ownerId = $user->getPartnerOwnerId();
        
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $ownerId)
            ->orWhere('created_by', $ownerId)
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
        
        $invoices = $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 10));
        
        return $this->success($invoices);
    }

    public function reconciliationSummary(Request $request)
    {
        $user = $request->user();
        $ownerId = $user->getPartnerOwnerId();
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $ownerId)
            ->orWhere('created_by', $ownerId)
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
        $ownerId = $user->getPartnerOwnerId();
        
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $ownerId)
            ->orWhere('created_by', $ownerId)
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
        $ownerId = $user->getPartnerOwnerId();
        
        $partnerCustomerIds = \App\Models\PartnerCustomer::where('partner_id', $ownerId)
            ->orWhere('created_by', $ownerId)
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
        $ownerId = $user->getPartnerOwnerId();
        
        $order = \App\Models\FulfillmentRequest::where('id', $validated['order_id'])
            ->whereHas('partnerCustomer', function($q) use ($ownerId) {
                $q->where('partner_id', $ownerId);
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

    private function notifyAdmins($title, $message, $type, $relatedTo = null)
    {
        $adminRoles = ['super_admin', 'operations_manager', 'operations'];
        $admins = User::where(function ($query) use ($adminRoles) {
            $query->whereHas('role', function ($q) use ($adminRoles) {
                $q->whereIn('slug', $adminRoles);
            })->orWhereHas('roles', function ($q) use ($adminRoles) {
                $q->whereIn('slug', $adminRoles);
            });
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
