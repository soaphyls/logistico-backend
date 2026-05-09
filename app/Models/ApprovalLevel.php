<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalLevel extends Model
{
    use HasFactory;

    protected $fillable = [
        'approval_chain_id',
        'level_order',
        'role_id',
    ];

    public function approvalChain(): BelongsTo
    {
        return $this->belongsTo(ApprovalChain::class, 'approval_chain_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}