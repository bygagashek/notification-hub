<?php

namespace App\Messaging;

use App\Enums\Channel;
use App\Enums\MessageStatus;
use App\Models\Message;
use App\Models\OutboxEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class MessageService
{
    public function __construct(private readonly string $exchange)
    {
    }

    /**
     * Stores the message and its outbox event in one transaction: either both exist or neither.
     *
     * The same Idempotency-Key with the same body returns the original message; with a
     * different body it is a client error.
     *
     * @param  array{channel: string, recipient: string, body: string}  $data
     *
     * @throws IdempotencyConflict
     */
    public function create(string $idempotencyKey, array $data): CreatedMessage
    {
        $hash = hash('sha256', json_encode([$data['channel'], $data['recipient'], $data['body']], JSON_THROW_ON_ERROR));

        $existing = Message::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $this->replay($existing, $hash);
        }

        try {
            $message = DB::transaction(function () use ($idempotencyKey, $hash, $data): Message {
                $message = Message::query()->create([
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $hash,
                    'channel' => Channel::from($data['channel']),
                    'recipient' => $data['recipient'],
                    'body' => $data['body'],
                    'status' => MessageStatus::Pending,
                ]);

                OutboxEvent::query()->create([
                    'exchange' => $this->exchange,
                    'routing_key' => $message->channel->value,
                    'payload' => ['message_id' => $message->id, 'attempt' => 1],
                ]);

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request with the same key committed first.
            return $this->replay(Message::query()->where('idempotency_key', $idempotencyKey)->firstOrFail(), $hash);
        }

        return new CreatedMessage($message, true);
    }

    private function replay(Message $existing, string $hash): CreatedMessage
    {
        if (! hash_equals($existing->request_hash, $hash)) {
            throw new IdempotencyConflict('Idempotency-Key was already used with a different request body');
        }

        return new CreatedMessage($existing, false);
    }
}
