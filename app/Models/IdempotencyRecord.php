<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class IdempotencyRecord extends Model
{
    use MassPrunable;

    protected $fillable = [
        'user_id',
        'operation',
        'idempotency_key',
        'request_hash',
        'response_status',
        'response_body',
        'expires_at',
    ];

    protected $hidden = [
        'request_hash',
        'response_body',
    ];

    protected $casts = [
        'response_status' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }
}
