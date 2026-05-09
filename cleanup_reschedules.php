<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\FulfillmentRequest;
use Illuminate\Support\Facades\DB;

try {
    DB::beginTransaction();

    // Identify duplicates based on notes
    $duplicates = FulfillmentRequest::where('notes', 'LIKE', '%reschedule from failed attempt: REQ-00016%')
        ->orWhere('notes', 'LIKE', '%reschedule from failed attempt: REQ-00015%')
        ->get();

    echo "Found " . $duplicates->count() . " duplicate requests to remove.\n";

    foreach ($duplicates as $dup) {
        echo "Removing duplicate ID: {$dup->id} (Status: {$dup->status})\n";
        $dup->delete();
    }

    // Reset the original order (ID 15) to awaiting_reschedule
    $original = FulfillmentRequest::find(15);
    if ($original) {
        echo "Resetting Original ID: 15 to awaiting_reschedule status.\n";
        $original->update([
            'status' => 'awaiting_reschedule',
            'requested_at' => '2026-05-09 00:00:00', // Set for tomorrow
            'failed_at' => null,
            'fail_reason' => null,
            'failed_by' => null,
            'notes' => $original->notes . "\n(Cleanup: Consolidated duplicates into this original request)"
        ]);
    }

    DB::commit();
    echo "Cleanup complete successfully.\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error during cleanup: " . $e->getMessage() . "\n";
}
