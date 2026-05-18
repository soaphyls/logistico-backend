<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add performance indexes to high-traffic tables.
     * Uses safe index creation that skips existing indexes.
     */
    public function up(): void
    {
        // ─── Fulfillment Requests (most queried table) ───
        Schema::table('fulfillment_requests', function (Blueprint $table) {
            // Single-column indexes (skip if exists)
            if (!$this->indexExists('fulfillment_requests', 'fulfillment_requests_status_index')) {
                $table->index('status');
            }
            if (!$this->indexExists('fulfillment_requests', 'fulfillment_requests_partner_customer_id_index')) {
                $table->index('partner_customer_id');
            }
            if (!$this->indexExists('fulfillment_requests', 'fulfillment_requests_dispatcher_id_index')) {
                $table->index('dispatcher_id');
            }
            if (!$this->indexExists('fulfillment_requests', 'fulfillment_requests_staff_id_index')) {
                $table->index('staff_id');
            }
            if (!$this->indexExists('fulfillment_requests', 'fulfillment_requests_completed_at_index')) {
                $table->index('completed_at');
            }
            if (!$this->indexExists('fulfillment_requests', 'fulfillment_requests_created_at_index')) {
                $table->index('created_at');
            }
            if (!$this->indexExists('fulfillment_requests', 'fulfillment_requests_invoice_id_index')) {
                $table->index('invoice_id');
            }
        });

        // Compound indexes (always safe since these are new)
        Schema::table('fulfillment_requests', function (Blueprint $table) {
            $table->index(['status', 'completed_at'], 'fr_status_completed_idx');
            $table->index(['dispatcher_id', 'status'], 'fr_dispatcher_status_idx');
            $table->index(['partner_customer_id', 'status'], 'fr_partner_cust_status_idx');
        });

        // ─── Shipments ───
        Schema::table('shipments', function (Blueprint $table) {
            if (!$this->indexExists('shipments', 'shipments_status_index')) {
                $table->index('status');
            }
            if (!$this->indexExists('shipments', 'shipments_dispatcher_id_index')) {
                $table->index('dispatcher_id');
            }
            if (!$this->indexExists('shipments', 'shipments_customer_id_index')) {
                $table->index('customer_id');
            }
            if (!$this->indexExists('shipments', 'shipments_tracking_number_index')) {
                $table->index('tracking_number');
            }
            if (!$this->indexExists('shipments', 'shipments_warehouse_id_index')) {
                $table->index('warehouse_id');
            }
            if (!$this->indexExists('shipments', 'shipments_created_at_index')) {
                $table->index('created_at');
            }
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'ship_status_created_idx');
            $table->index(['dispatcher_id', 'status'], 'ship_dispatcher_status_idx');
        });

        // ─── Pickup Deliveries ───
        Schema::table('pickup_deliveries', function (Blueprint $table) {
            if (!$this->indexExists('pickup_deliveries', 'pickup_deliveries_dispatcher_id_index')) {
                $table->index('dispatcher_id');
            }
            if (!$this->indexExists('pickup_deliveries', 'pickup_deliveries_shipment_id_index')) {
                $table->index('shipment_id');
            }
            if (!$this->indexExists('pickup_deliveries', 'pickup_deliveries_type_index')) {
                $table->index('type');
            }
            if (!$this->indexExists('pickup_deliveries', 'pickup_deliveries_scheduled_date_index')) {
                $table->index('scheduled_date');
            }
            if (!$this->indexExists('pickup_deliveries', 'pickup_deliveries_status_index')) {
                $table->index('status');
            }
        });

        Schema::table('pickup_deliveries', function (Blueprint $table) {
            $table->index(['dispatcher_id', 'scheduled_date'], 'pd_dispatcher_date_idx');
            $table->index(['type', 'status'], 'pd_type_status_idx');
        });

        // ─── Notifications ───
        if (Schema::hasTable('notifications')) {
            Schema::table('notifications', function (Blueprint $table) {
                if (Schema::hasColumn('notifications', 'user_id') && !$this->indexExists('notifications', 'notifications_user_id_index')) {
                    $table->index('user_id');
                }
                if (Schema::hasColumn('notifications', 'is_read') && Schema::hasColumn('notifications', 'user_id')) {
                    $table->index(['user_id', 'is_read'], 'notif_user_read_idx');
                }
            });
        }

        // ─── Partner Customers ───
        Schema::table('partner_customers', function (Blueprint $table) {
            if (!$this->indexExists('partner_customers', 'partner_customers_partner_id_index')) {
                $table->index('partner_id');
            }
            if (!$this->indexExists('partner_customers', 'partner_customers_staff_id_index')) {
                $table->index('staff_id');
            }
            if (!$this->indexExists('partner_customers', 'partner_customers_customer_id_index')) {
                $table->index('customer_id');
            }
            if (!$this->indexExists('partner_customers', 'partner_customers_warehouse_id_index')) {
                $table->index('warehouse_id');
            }
        });

        // ─── Partner Products ───
        Schema::table('partner_products', function (Blueprint $table) {
            if (!$this->indexExists('partner_products', 'partner_products_partner_customer_id_index')) {
                $table->index('partner_customer_id');
            }
            if (!$this->indexExists('partner_products', 'partner_products_is_approved_index')) {
                $table->index('is_approved');
            }
            $table->index(['partner_customer_id', 'is_approved'], 'pp_cust_approved_idx');
        });

        // ─── Fulfillment Activity Logs ───
        if (Schema::hasTable('fulfillment_activity_logs')) {
            Schema::table('fulfillment_activity_logs', function (Blueprint $table) {
                if (!$this->indexExists('fulfillment_activity_logs', 'fulfillment_activity_logs_fulfillment_request_id_index')) {
                    $table->index('fulfillment_request_id');
                }
                if (Schema::hasColumn('fulfillment_activity_logs', 'user_id') && !$this->indexExists('fulfillment_activity_logs', 'fulfillment_activity_logs_user_id_index')) {
                    $table->index('user_id');
                }
            });
        }

        // ─── Invoices ───
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (!$this->indexExists('invoices', 'invoices_status_index')) {
                    $table->index('status');
                }
                if (Schema::hasColumn('invoices', 'customer_id') && !$this->indexExists('invoices', 'invoices_customer_id_index')) {
                    $table->index('customer_id');
                }
                if (Schema::hasColumn('invoices', 'shipment_id') && !$this->indexExists('invoices', 'invoices_shipment_id_index')) {
                    $table->index('shipment_id');
                }
                if (!$this->indexExists('invoices', 'invoices_created_at_index')) {
                    $table->index('created_at');
                }
            });
        }

        // ─── Dispatchers ───
        Schema::table('dispatchers', function (Blueprint $table) {
            if (!$this->indexExists('dispatchers', 'dispatchers_user_id_index')) {
                $table->index('user_id');
            }
            if (!$this->indexExists('dispatchers', 'dispatchers_is_available_index')) {
                $table->index('is_available');
            }
        });

        // ─── Expenses ───
        if (Schema::hasTable('expenses') && Schema::hasColumn('expenses', 'expense_date')) {
            Schema::table('expenses', function (Blueprint $table) {
                if (!$this->indexExists('expenses', 'expenses_expense_date_index')) {
                    $table->index('expense_date');
                }
            });
        }
    }

    /**
     * Helper: Check if an index exists on a table.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Compound indexes (always created by this migration)
        Schema::table('fulfillment_requests', function (Blueprint $table) {
            $table->dropIndex('fr_status_completed_idx');
            $table->dropIndex('fr_dispatcher_status_idx');
            $table->dropIndex('fr_partner_cust_status_idx');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('ship_status_created_idx');
            $table->dropIndex('ship_dispatcher_status_idx');
        });

        Schema::table('pickup_deliveries', function (Blueprint $table) {
            $table->dropIndex('pd_dispatcher_date_idx');
            $table->dropIndex('pd_type_status_idx');
        });

        if (Schema::hasTable('notifications')) {
            Schema::table('notifications', function (Blueprint $table) {
                if ($this->indexExists('notifications', 'notif_user_read_idx')) {
                    $table->dropIndex('notif_user_read_idx');
                }
            });
        }

        Schema::table('partner_products', function (Blueprint $table) {
            $table->dropIndex('pp_cust_approved_idx');
        });

        // Note: We don't drop single-column indexes since we don't know
        // which ones existed before this migration. Indexes are harmless to keep.
    }
};
