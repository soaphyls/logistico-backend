<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BotConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'api_key',
        'api_secret',
        'webhook_url',
        'is_active',
        'settings'
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean'
    ];
}
