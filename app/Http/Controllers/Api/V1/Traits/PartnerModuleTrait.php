<?php

namespace App\Http\Controllers\Api\V1\Traits;

use App\Models\PartnerModule;
use App\Models\User;
use App\Models\Notification;

trait PartnerModuleTrait
{
    /**
     * Check if the partner logistics module is enabled.
     * Throws an exception if disabled.
     */
    private function checkModuleEnabled(): void
    {
        if (!PartnerModule::isEnabled()) {
            throw new \Exception('Partner module is not enabled');
        }
    }

    /**
     * Notify all admin/ops users about a partner event.
     */
    private function notifyAdmins($title, $message, $type, $relatedTo = null)
    {
        $admins = User::whereHas('role', function($q) {
            $q->whereIn('slug', ['super_admin', 'operations_manager', 'operations']);
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
