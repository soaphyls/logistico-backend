<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\ShipmentController;
use App\Http\Controllers\Api\V1\PickupDeliveryController;
use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InventoryStockController;
use App\Http\Controllers\Api\V1\InventoryTransferController;
use App\Http\Controllers\Api\V1\InboundOrderController;
use App\Http\Controllers\Api\V1\CycleCountController;
use App\Http\Controllers\Api\V1\DispatcherController;
use App\Http\Controllers\Api\V1\VehicleController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\SalaryController;
use App\Http\Controllers\Api\V1\PartnerController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\CompanySettingsController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PartnerAuthController;
use App\Http\Controllers\Api\V1\BotController;
use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\ReconciliationController;

Route::prefix('v1')->group(function () {

    // Public routes (no auth required)
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('partner/login', [PartnerAuthController::class, 'login']);
    Route::get('shipments/track/{tracking_number}', [ShipmentController::class, 'track']);
    Route::post('bot/webhook/{platform}', [BotController::class, 'handle']);

    // Public settings route (read-only, no auth required)
    Route::get('settings/public', [SettingsController::class, 'publicIndex']);

    // Partner public routes
    Route::get('partners/module', [PartnerController::class, 'moduleStatus']);
    Route::get('partners/dashboard', [PartnerController::class, 'dashboard']);

    // Temporary route for shared hosting to link storage
    Route::get('storage-link', function () {
        $target = storage_path('app/public');
        $shortcut = public_path('storage');
        if (file_exists($shortcut)) {
            return 'The "public/storage" directory already exists.';
        }
        try {
            app('files')->link($target, $shortcut);
            return 'The [public/storage] directory has been linked.';
        } catch (\Exception $e) {
            return 'Failed to link: ' . $e->getMessage();
        }
    });

    // Protected routes (auth required)
    Route::middleware('auth:sanctum')->group(function () {

        // Auth routes
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/profile', [AuthController::class, 'updateProfile']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);

        // Dashboard
        Route::get('dashboard/stats', [DashboardController::class, 'stats']);

        // Users — super_admin only
        // Users management
        Route::middleware('role:super_admin,operations_manager,accountant')->group(function () {
            Route::get('users', [UserController::class, 'index']);
        });

        Route::middleware('role:super_admin')->group(function () {
            Route::post('users', [UserController::class, 'store']);
            Route::get('users/{user}', [UserController::class, 'show']);
            Route::put('users/{user}', [UserController::class, 'update']);
            Route::delete('users/{user}', [UserController::class, 'destroy']);
            Route::patch('users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);

            // Permissions Management — super_admin only
            Route::get('permissions', [PermissionController::class, 'permissions']);
            Route::get('roles', [PermissionController::class, 'roles']);
            Route::get('users/permissions', [PermissionController::class, 'users']);
            Route::get('users/{user}/permissions', [PermissionController::class, 'userPermissions']);
            Route::put('users/{user}/permissions', [PermissionController::class, 'updateUserPermissions']);
            Route::post('users/{user}/permissions/grant', [PermissionController::class, 'grantPermission']);
            Route::post('users/{user}/permissions/revoke', [PermissionController::class, 'revokePermission']);
            Route::delete('users/{user}/permissions/remove', [PermissionController::class, 'removeOverride']);
            Route::post('permissions/check', [PermissionController::class, 'checkPermission']);
        });

        // Company Settings — super_admin only
        Route::middleware('role:super_admin')->group(function () {
            Route::get('company-settings', [CompanySettingsController::class, 'index']);
            Route::put('company-settings', [CompanySettingsController::class, 'update']);
        });

        // Settings — super_admin only
        Route::middleware('role:super_admin')->group(function () {
            Route::get('settings', [SettingsController::class, 'index']);
            Route::post('settings/general', [SettingsController::class, 'updateGeneral']);
            Route::post('settings/payment', [SettingsController::class, 'updatePayment']);
            Route::delete('settings/image', [SettingsController::class, 'deleteImage']);

            // Bot Settings — super_admin only
            Route::get('bot/configs', [BotController::class, 'index']);
            Route::post('bot/configs', [BotController::class, 'update']);
            Route::post('bot/sync-webhook/{platform}', [BotController::class, 'syncWebhook']);
        });

        // Bot Code Generation — all authenticated users
        Route::post('bot/generate-code', [BotController::class, 'generateCode']);
        Route::post('bot/generate-code/{userId}', [BotController::class, 'generateCodeForUser']);

        // Customers — super_admin, operations_manager, customer_service, operations
        Route::middleware('role:super_admin,operations_manager,customer_service,operations')->group(function () {
            Route::get('customers', [CustomerController::class, 'index']);
            Route::get('customers/analytics', [CustomerController::class, 'analytics']);
            Route::post('customers', [CustomerController::class, 'store']);
            Route::get('customers/{customer}', [CustomerController::class, 'show']);
            Route::put('customers/{customer}', [CustomerController::class, 'update']);
            Route::delete('customers/{customer}', [CustomerController::class, 'destroy']);
            Route::get('customers/{customer}/shipments', [CustomerController::class, 'shipments']);
            Route::get('customers/{customer}/invoices', [CustomerController::class, 'invoices']);
            Route::get('customers/{customer}/statement', [CustomerController::class, 'statement']);
        });

        // Shipments — super_admin, operations_manager, customer_service, warehouse_officer, accountant, operations, dispatcher (read)
        Route::middleware('role:super_admin,operations_manager,customer_service,warehouse_officer,accountant,operations,dispatcher')->group(function () {
            Route::get('shipments', [ShipmentController::class, 'index']);
            Route::get('shipments/{shipment}', [ShipmentController::class, 'show']);
        });

        // Shipments — super_admin, operations_manager, customer_service, warehouse_officer, operations (write access)
        Route::middleware('role:super_admin,operations_manager,customer_service,warehouse_officer,operations')->group(function () {
            Route::post('shipments', [ShipmentController::class, 'store']);
            Route::put('shipments/{shipment}', [ShipmentController::class, 'update']);
            Route::delete('shipments/{shipment}', [ShipmentController::class, 'destroy']);
            Route::patch('shipments/{shipment}/status', [ShipmentController::class, 'updateStatus']);
            Route::post('shipments/{shipment}/assign-dispatcher', [ShipmentController::class, 'assignDispatcher']);
            Route::post('shipments/{shipment}/proof-of-delivery', [ShipmentController::class, 'uploadProof']);
        });

        // Pickup & Delivery — super_admin, operations_manager, customer_service, operations, warehouse_officer, accountant, dispatcher
        Route::middleware('role:super_admin,operations_manager,customer_service,operations,warehouse_officer,accountant,dispatcher')->group(function () {
            Route::get('pickup-deliveries', [PickupDeliveryController::class, 'index']);
            Route::post('pickup-deliveries', [PickupDeliveryController::class, 'store']);
            Route::get('pickup-deliveries/{pickupDelivery}', [PickupDeliveryController::class, 'show']);
            Route::put('pickup-deliveries/{pickupDelivery}', [PickupDeliveryController::class, 'update']);
            Route::patch('pickup-deliveries/{pickupDelivery}/assign', [PickupDeliveryController::class, 'assign']);
            Route::patch('pickup-deliveries/{pickupDelivery}/status', [PickupDeliveryController::class, 'updateStatus']);
            Route::get('pickup-deliveries/dispatcher/{dispatcher}/today', [PickupDeliveryController::class, 'dispatcherToday']);
        });

        // Warehouses — super_admin, operations_manager, warehouse_officer, operations
        Route::middleware('role:super_admin,operations_manager,warehouse_officer,operations')->group(function () {
            Route::get('warehouses', [WarehouseController::class, 'index']);
            Route::post('warehouses', [WarehouseController::class, 'store']);
            Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show']);
            Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update']);
            Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy']);
            Route::get('warehouses/{warehouse}/shipments', [WarehouseController::class, 'shipments']);
            Route::get('warehouses/{warehouse}/inventory', [WarehouseController::class, 'inventory']);
        });

        // Partner Module - protected routes
        Route::middleware('auth:sanctum')->group(function () {
            // Module toggle
            Route::post('partners/module', [PartnerController::class, 'toggleModule']);

            // Warehouses for partners
            Route::get('partners/warehouses', [PartnerController::class, 'warehouses']);

            // Dispatchers for partners
            Route::get('partners/dispatchers/available', [PartnerController::class, 'availableDispatchers']);

            // Staff
            Route::get('partners/staff', [PartnerController::class, 'staff']);

            // Fulfillment Requests
            Route::get('partners/requests', [PartnerController::class, 'requests']);

            // Fulfillment Requests (write operations need auth)
            Route::post('partners/requests', [PartnerController::class, 'createRequest']);
            Route::get('partners/requests/{id}', [PartnerController::class, 'showRequest']);
            Route::put('partners/requests/{id}/acknowledge', [PartnerController::class, 'acknowledgeRequest']);
            Route::put('partners/requests/{id}/accept', [PartnerController::class, 'acceptRequest']);
            Route::put('partners/requests/{id}/reject', [PartnerController::class, 'rejectRequest']);
            Route::put('partners/requests/{id}/assign-dispatcher', [PartnerController::class, 'assignDispatcher']);
            Route::put('partners/requests/{id}/complete', [PartnerController::class, 'completeRequest']);
            Route::put('partners/requests/{id}/fail', [PartnerController::class, 'failDelivery']);
            Route::put('partners/requests/{id}/cancel', [PartnerController::class, 'cancelRequest']);
            Route::put('partners/requests/{id}/start-delivery', [PartnerController::class, 'startDelivery']);
            Route::put('partners/requests/{id}/delay', [PartnerController::class, 'delayRequest']);
            Route::put('partners/requests/{id}/reschedule', [PartnerController::class, 'rescheduleRequest']);

            // Analytics
            Route::get('partners/analytics', [PartnerController::class, 'analytics']);
            Route::get('partners/staff/performance', [PartnerController::class, 'staffPerformance']);

            // Partner Customers
            Route::get('partners/customers', [PartnerController::class, 'customers']);
            Route::post('partners/customers', [PartnerController::class, 'storeCustomer']);
            Route::get('partners/customers/{id}', [PartnerController::class, 'showCustomer']);
            Route::put('partners/customers/{id}', [PartnerController::class, 'updateCustomer']);
            Route::delete('partners/customers/{id}', [PartnerController::class, 'deleteCustomer']);
            Route::put('partners/customers/{id}/assign-staff', [PartnerController::class, 'assignStaff']);

            // Products
            Route::get('partners/products', [PartnerController::class, 'products']);
            Route::get('partners/products/pending', [PartnerController::class, 'pendingProducts']);
            Route::post('partners/products', [PartnerController::class, 'storeProduct']);
            Route::put('partners/products/{id}/approve', [PartnerController::class, 'approveProduct']);
            Route::put('partners/products/{id}/reject', [PartnerController::class, 'rejectProduct']);
            Route::put('partners/products/{id}', [PartnerController::class, 'updateProduct']);
            Route::delete('partners/products/{id}', [PartnerController::class, 'deleteProduct']);

            // Customer Billing & Transactions
            Route::get('partners/customers/{id}/invoices', [PartnerController::class, 'customerInvoices']);
            Route::get('partners/customers/{id}/payments', [PartnerController::class, 'customerPayments']);
            Route::get('partners/customers/{id}/transactions', [PartnerController::class, 'customerTransactions']);
        });

        // Inventory — super_admin, warehouse_officer, operations_manager
        Route::middleware('role:super_admin,warehouse_officer,operations_manager')->group(function () {
            Route::get('inventory', [InventoryController::class, 'index']);
            Route::post('inventory', [InventoryController::class, 'store']);
            Route::get('inventory/{inventory}', [InventoryController::class, 'show']);
            Route::put('inventory/{inventory}', [InventoryController::class, 'update']);
            Route::delete('inventory/{inventory}', [InventoryController::class, 'destroy']);
            Route::post('inventory/{inventory}/adjust', [InventoryController::class, 'adjust']);
            Route::get('inventory/low-stock', [InventoryController::class, 'lowStock']);
        });

        // Inventory Stock (WMS) — super_admin, warehouse_officer, operations_manager
        Route::middleware('role:super_admin,warehouse_officer,operations_manager')->group(function () {
            Route::get('inventory-stocks', [InventoryStockController::class, 'index']);
            Route::post('inventory-stocks', [InventoryStockController::class, 'store']);
            Route::get('inventory-stocks/{inventoryStock}', [InventoryStockController::class, 'show']);
            Route::put('inventory-stocks/{inventoryStock}', [InventoryStockController::class, 'update']);
            Route::post('inventory-stocks/{inventoryStock}/adjust', [InventoryStockController::class, 'adjust']);
            Route::get('inventory-stocks/low-stock', [InventoryStockController::class, 'lowStock']);
            Route::get('inventory-stocks/transactions', [InventoryStockController::class, 'getTransactions']);
        });

        // Inventory Transfers — super_admin, warehouse_officer, operations_manager
        Route::middleware('role:super_admin,warehouse_officer,operations_manager')->group(function () {
            Route::get('inventory-transfers', [InventoryTransferController::class, 'index']);
            Route::post('inventory-transfers', [InventoryTransferController::class, 'store']);
            Route::get('inventory-transfers/{inventoryTransfer}', [InventoryTransferController::class, 'show']);
            Route::post('inventory-transfers/{inventoryTransfer}/submit', [InventoryTransferController::class, 'submit']);
            Route::post('inventory-transfers/{inventoryTransfer}/approve', [InventoryTransferController::class, 'approve']);
            Route::post('inventory-transfers/{inventoryTransfer}/reject', [InventoryTransferController::class, 'reject']);
            Route::post('inventory-transfers/{inventoryTransfer}/ship', [InventoryTransferController::class, 'ship']);
            Route::post('inventory-transfers/{inventoryTransfer}/receive', [InventoryTransferController::class, 'receive']);
        });

        // Inbound Orders (ASN) — super_admin, warehouse_officer, operations_manager
        Route::middleware('role:super_admin,warehouse_officer,operations_manager')->group(function () {
            Route::get('inbound-orders', [InboundOrderController::class, 'index']);
            Route::post('inbound-orders', [InboundOrderController::class, 'store']);
            Route::get('inbound-orders/{inboundOrder}', [InboundOrderController::class, 'show']);
            Route::post('inbound-orders/{inboundOrder}/receive', [InboundOrderController::class, 'receive']);
            Route::post('inbound-orders/{inboundOrder}/cancel', [InboundOrderController::class, 'cancel']);
        });

        // Cycle Counts — super_admin, warehouse_officer, operations_manager
        Route::middleware('role:super_admin,warehouse_officer,operations_manager')->group(function () {
            Route::get('cycle-counts', [CycleCountController::class, 'index']);
            Route::post('cycle-counts', [CycleCountController::class, 'store']);
            Route::get('cycle-counts/{cycleCount}', [CycleCountController::class, 'show']);
            Route::post('cycle-counts/{cycleCount}/assign', [CycleCountController::class, 'assign']);
            Route::post('cycle-counts/{cycleCount}/start', [CycleCountController::class, 'start']);
            Route::post('cycle-counts/{cycleCount}/submit-count', [CycleCountController::class, 'submitCount']);
            Route::post('cycle-counts/{cycleCount}/complete', [CycleCountController::class, 'complete']);
            Route::post('cycle-counts/{cycleCount}/adjust', [CycleCountController::class, 'adjust']);
        });

        // Dispatchers available — all authenticated users (for dispatcher assignment dropdown)
        Route::get('dispatchers/available', [DispatcherController::class, 'available']);

        // Dispatchers — super_admin, operations_manager
        Route::middleware('role:super_admin,operations_manager')->group(function () {
            Route::get('dispatchers', [DispatcherController::class, 'index']);
            Route::post('dispatchers', [DispatcherController::class, 'store']);
            Route::get('dispatchers/{dispatcher}', [DispatcherController::class, 'show']);
            Route::put('dispatchers/{dispatcher}', [DispatcherController::class, 'update']);
            Route::delete('dispatchers/{dispatcher}', [DispatcherController::class, 'destroy']);
            Route::patch('dispatchers/{dispatcher}/assign-vehicle', [DispatcherController::class, 'assignVehicle']);
            Route::get('dispatchers/{dispatcher}/deliveries', [DispatcherController::class, 'deliveries']);
        });

        // Vehicles — super_admin, operations_manager
        Route::middleware('role:super_admin,operations_manager')->group(function () {
            Route::get('vehicles', [VehicleController::class, 'index']);
            Route::post('vehicles', [VehicleController::class, 'store']);
            Route::get('vehicles/{vehicle}', [VehicleController::class, 'show']);
            Route::put('vehicles/{vehicle}', [VehicleController::class, 'update']);
            Route::delete('vehicles/{vehicle}', [VehicleController::class, 'destroy']);
            Route::patch('vehicles/{vehicle}/maintenance', [VehicleController::class, 'maintenance']);
            Route::get('vehicles/due-maintenance', [VehicleController::class, 'dueMaintenance']);
        });

        // Invoices — super_admin, accountant, operations_manager
        Route::middleware('role:super_admin,accountant,operations_manager')->group(function () {
            Route::get('invoices', [InvoiceController::class, 'index']);
            Route::post('invoices', [InvoiceController::class, 'store']);
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
            Route::put('invoices/{invoice}', [InvoiceController::class, 'update']);
            Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy']);
            Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send']);
            Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'payments']);
            Route::get('invoices/overdue', [InvoiceController::class, 'overdue']);
            Route::post('invoices/{invoice}/generate-payment-link', [InvoiceController::class, 'generatePaymentLink']);
            Route::get('invoices/summary', [InvoiceController::class, 'summary']);
            Route::get('invoices/{invoice}/payment-link', [InvoiceController::class, 'getPaymentLink']);

            // Payments
            Route::get('payments', [PaymentController::class, 'index']);
            Route::post('payments', [PaymentController::class, 'store']);
            Route::get('payments/{payment}', [PaymentController::class, 'show']);
            Route::put('payments/{payment}', [PaymentController::class, 'update']);
            Route::delete('payments/{payment}', [PaymentController::class, 'destroy']);
        });

        // Expenses — super_admin, accountant
        Route::middleware('role:super_admin,accountant')->group(function () {
            Route::get('expenses', [ExpenseController::class, 'index']);
            Route::post('expenses', [ExpenseController::class, 'store']);
            Route::get('expenses/{expense}', [ExpenseController::class, 'show']);
            Route::put('expenses/{expense}', [ExpenseController::class, 'update']);
            Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy']);
            Route::get('expenses/summary', [ExpenseController::class, 'summary']);
        });

        // Salaries — super_admin, accountant
        Route::middleware('role:super_admin,accountant')->group(function () {
            Route::get('salaries', [SalaryController::class, 'index']);
            Route::post('salaries', [SalaryController::class, 'store']);
            Route::get('salaries/dashboard', [SalaryController::class, 'dashboard']);
            Route::get('salaries/employees', [SalaryController::class, 'employees']);
            Route::post('salaries/bulk-pay', [SalaryController::class, 'bulkPay']);
            Route::get('salaries/{salary}', [SalaryController::class, 'show']);
            Route::put('salaries/{salary}', [SalaryController::class, 'update']);
            Route::delete('salaries/{salary}', [SalaryController::class, 'destroy']);
            Route::post('salaries/{salary}/mark-paid', [SalaryController::class, 'markAsPaid']);
        });

        // Tasks — read access for all, write access for super_admin, operations_manager
        Route::get('tasks', [TaskController::class, 'index']);
        Route::get('tasks/{task}', [TaskController::class, 'show']);
        Route::get('tasks/my-tasks', [TaskController::class, 'myTasks']);

        Route::middleware('role:super_admin,operations_manager')->group(function () {
            Route::post('tasks', [TaskController::class, 'store']);
            Route::put('tasks/{task}', [TaskController::class, 'update']);
            Route::delete('tasks/{task}', [TaskController::class, 'destroy']);
            Route::patch('tasks/{task}/status', [TaskController::class, 'updateStatus']);
        });

        // Reports — super_admin, accountant, operations_manager
        Route::middleware('role:super_admin,accountant,operations_manager')->group(function () {
            Route::get('reports/shipments', [ReportController::class, 'shipments']);
            Route::get('reports/revenue', [ReportController::class, 'revenue']);
            Route::get('reports/expenses', [ReportController::class, 'expenses']);
            Route::get('reports/profit', [ReportController::class, 'profit']);
            Route::get('reports/dispatchers', [ReportController::class, 'dispatchers']);
            Route::get('reports/delivery-success', [ReportController::class, 'deliverySuccess']);
            Route::get('reports/export/{type}', [ReportController::class, 'export']);
        });

        // Notifications — all authenticated users
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);

        // Partner routes — partner role only
        Route::middleware('role:partner')->group(function () {
            Route::get('partner/orders', [PartnerAuthController::class, 'orders']);
            Route::post('partner/orders', [PartnerAuthController::class, 'createOrder']);
            Route::get('partner/orders/{id}', [PartnerAuthController::class, 'showOrder']);
            Route::put('partner/orders/{id}/cancel', [PartnerAuthController::class, 'cancelOrder']);
            Route::put('partner/orders/{id}/accept', [PartnerAuthController::class, 'acceptOrder']);
            Route::put('partner/orders/{id}/reject', [PartnerAuthController::class, 'rejectOrder']);
            Route::put('partner/orders/{id}/counter-offer', [PartnerAuthController::class, 'counterOfferOrder']);
            Route::put('partner/orders/{id}/respond', [PartnerAuthController::class, 'respondToFailure']);
            Route::get('partner/inventory', [PartnerAuthController::class, 'inventory']);
            Route::post('partner/inventory', [PartnerAuthController::class, 'addInventory']);
            Route::get('partner/billing/summary', [PartnerAuthController::class, 'billingSummary']);
            Route::get('partner/invoices', [PartnerAuthController::class, 'invoices']);
            Route::get('partner/profile', [PartnerAuthController::class, 'me']);

            // Partner Reconciliation Routes
            Route::get('partner/reconciliation/summary', [PartnerAuthController::class, 'reconciliationSummary']);
            Route::get('partner/reconciliation/orders', [PartnerAuthController::class, 'reconciliationOrders']);
            Route::get('partner/reconciliation/statement', [PartnerAuthController::class, 'reconciliationStatement']);
            Route::post('partner/reconciliation/dispute', [PartnerAuthController::class, 'raiseDispute']);
        });

        // Wallet & Ledger Routes
        Route::get('wallets/me', [WalletController::class, 'me']);
        Route::get('wallets/transactions', [WalletController::class, 'transactions']);
        Route::post('wallets/deposit', [WalletController::class, 'deposit']);
        Route::get('cod-ledger', [WalletController::class, 'codLedger']);

        // Reconciliation Routes
        Route::get('reconciliation/summary', [ReconciliationController::class, 'summary']);
        Route::get('reconciliation/partners', [ReconciliationController::class, 'partnersList']);
        Route::get('reconciliation/partners/{id}', [ReconciliationController::class, 'partnerBreakdown']);
        Route::post('reconciliation/generate-invoice', [ReconciliationController::class, 'generateInvoice']);
        Route::post('reconciliation/settle', [ReconciliationController::class, 'settle']);
        Route::post('reconciliation/dispute', [ReconciliationController::class, 'dispute']);
        Route::post('reconciliation/notify-partner', [ReconciliationController::class, 'notifyPartner']);

        // Daily Activity Routes
        Route::middleware('role:super_admin,operations_manager,operations,accountant')->group(function () {
            Route::get('operations/partner-daily', [ActivityController::class, 'partnerDaily']);
            Route::get('operations/dispatcher-daily', [ActivityController::class, 'dispatcherDaily']);
        });
    });
});
