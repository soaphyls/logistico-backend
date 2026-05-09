<?php

namespace App\Traits;

use App\Models\ApprovalChain;
use App\Models\ApprovalRequest;
use App\Models\ApprovalLog;
use Illuminate\Support\Facades\Auth;

trait Approvable
{
    public static function bootApprovable()
    {
        static::created(function ($model) {
            $model->createApprovalRequestIfNeeded();
        });

        static::deleted(function ($model) {
            ApprovalRequest::where('approvable_type', get_class($model))
                ->where('approvable_id', $model->id)
                ->delete();
        });
    }

    public function approvalRequest()
    {
        return $this->morphOne(ApprovalRequest::class, 'approvable');
    }

    public function createApprovalRequestIfNeeded(): ?ApprovalRequest
    {
        $chain = ApprovalChain::where('module', $this->getApprovalModule())
            ->where('is_active', true)
            ->first();

        if (!$chain) {
            return null;
        }

        $approvalRequest = ApprovalRequest::create([
            'approval_chain_id' => $chain->id,
            'approvable_type' => get_class($this),
            'approvable_id' => $this->id,
            'current_level_order' => 1,
            'status' => ApprovalRequest::STATUS_PENDING,
        ]);

        return $approvalRequest;
    }

    public function getApprovalModule(): string
    {
        return $this->approvalModule ?? strtolower(class_basename($this));
    }

    public function approve(int $userId, string $userRoleId, string $comments = null): bool
    {
        $approvalRequest = $this->approvalRequest;

        if (!$approvalRequest || !$approvalRequest->canApprove($userRoleId)) {
            return false;
        }

        $isComplete = $approvalRequest->moveToNextLevel();

        ApprovalLog::create([
            'approval_request_id' => $approvalRequest->id,
            'user_id' => $userId,
            'level_order' => $approvalRequest->current_level_order,
            'action' => ApprovalLog::ACTION_APPROVED,
            'comments' => $comments,
        ]);

        $this->onApprovalComplete($isComplete);

        return true;
    }

    public function reject(int $userId, string $userRoleId, string $reason): bool
    {
        $approvalRequest = $this->approvalRequest;

        if (!$approvalRequest || !$approvalRequest->canApprove($userRoleId)) {
            return false;
        }

        $approvalRequest->update([
            'status' => ApprovalRequest::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ]);

        ApprovalLog::create([
            'approval_request_id' => $approvalRequest->id,
            'user_id' => $userId,
            'level_order' => $approvalRequest->current_level_order,
            'action' => ApprovalLog::ACTION_REJECTED,
            'comments' => $reason,
        ]);

        $this->onRejection();

        return true;
    }

    protected function onApprovalComplete(bool $isFinalApproval): void
    {
        // Override in model if needed
    }

    protected function onRejection(): void
    {
        // Override in model if needed
    }

    public function isApproved(): bool
    {
        $approvalRequest = $this->approvalRequest;
        
        return $approvalRequest && $approvalRequest->status === ApprovalRequest::STATUS_APPROVED;
    }

    public function isPendingApproval(): bool
    {
        $approvalRequest = $this->approvalRequest;
        
        return $approvalRequest && $approvalRequest->status === ApprovalRequest::STATUS_PENDING;
    }

    public function isRejected(): bool
    {
        $approvalRequest = $this->approvalRequest;
        
        return $approvalRequest && $approvalRequest->status === ApprovalRequest::STATUS_REJECTED;
    }
}