<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BotSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_user_id',
        'user_id',
        'platform',
        'last_intent',
        'context_data'
    ];

    protected $casts = [
        'context_data' => 'array'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
