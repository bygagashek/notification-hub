<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $exchange
 * @property string $routing_key
 * @property array<string, mixed> $payload
 * @property Carbon $created_at
 * @property Carbon|null $published_at
 */
class OutboxEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'outbox';

    protected $fillable = [
        'exchange',
        'routing_key',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
