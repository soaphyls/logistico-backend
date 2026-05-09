<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

trait LogActivity
{
    public function logActivity(string $action, ?Model $model = null, array $old = [], array $new = []): void
    {
        $description = $this->buildActivityDescription($action, $model);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'model_type' => $model ? get_class($model) : null,
            'model_id' => $model?->id,
            'description' => $description,
            'ip_address' => request()->ip(),
            'old_values' => !empty($old) ? $old : null,
            'new_values' => !empty($new) ? $new : null,
        ]);
    }

    private function buildActivityDescription(string $action, ?Model $model): string
    {
        $actionLabels = [
            'created' => 'created',
            'updated' => 'updated',
            'deleted' => 'deleted',
            'assigned' => 'assigned',
            'status_changed' => 'changed status of',
            'login' => 'logged in',
            'logout' => 'logged out',
        ];

        $label = $actionLabels[$action] ?? $action;
        $modelName = $model ? class_basename($model) : 'record';

        return ucfirst($label) . ' ' . $modelName;
    }
}
