<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'approval_chain_id',
        'approvable_type',
        'approvable_id',
        'current_level_order',
        'status',
        'rejection_reason',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public function approvalChain(): BelongsTo
    {
        return $this->belongsTo(ApprovalChain::class, 'approval_chain_id');
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo('approvable', 'approvable_type', 'approvable_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ApprovalLog::class, 'approval_request_id');
    }

    public function canApprove(int $userRoleId): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        $currentLevel = $this->approvalChain->levels()
            ->where('level_order', $this->current_level_order)
            ->first();

        return $currentLevel && $currentLevel->role_id === $userRoleId;
    }

    public function isComplete(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED]);
    }

    public function moveToNextLevel(): bool
    {
        $maxLevel = $this->approvalChain->getMaxLevel();
        
        if ($this->current_level_order >= $maxLevel) {
            $this->update(['status' => self::STATUS_APPROVED]);
            return true;
        }

        $this->update(['current_level_order' => $this->current_level_order + 1]);
        return false;
    }
}