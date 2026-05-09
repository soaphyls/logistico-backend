<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalChain extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'module',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function levels(): HasMany
    {
        return $this->hasMany(ApprovalLevel::class, 'approval_chain_id')->orderBy('level_order');
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'approval_chain_id');
    }

    public function getMaxLevel(): int
    {
        return $this->levels()->max('level_order') ?? 0;
    }
}