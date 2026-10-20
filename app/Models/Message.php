<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\MessageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $idempotency_key
 * @property string $request_hash
 * @property Channel $channel
 * @property string $recipient
 * @property string $body
 * @property MessageStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $sent_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Message extends Model
{
    use HasUlids;

    protected $fillable = [
        'idempotency_key',
        'request_hash',
        'channel',
        'recipient',
        'body',
        'status',
    ];

    protected $attributes = [
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'status' => MessageStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }
}
