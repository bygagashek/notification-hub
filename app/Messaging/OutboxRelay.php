<?php

namespace App\Messaging;

use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;

/**
 * Moves committed outbox rows to the broker.
 *
 * Rows are marked published only after the broker confirms them. A crash between confirm
 * and commit republishes the batch, so delivery is at-least-once and consumers dedupe.
 */
class OutboxRelay
{
    public function __construct(private readonly Broker $broker)
    {
    }

    public function relayBatch(int $limit): int
    {
        return DB::transaction(function () use ($limit): int {
            // SKIP LOCKED lets several relay processes share the table without waiting on each other.
            $events = OutboxEvent::query()
                ->whereNull('published_at')
                ->orderBy('id')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            if ($events->isEmpty()) {
                return 0;
            }

            foreach ($events as $event) {
                $this->broker->publish($event->exchange, $event->routing_key, $event->payload);
            }
            $this->broker->confirm();

            OutboxEvent::query()
                ->whereIn('id', $events->modelKeys())
                ->update(['published_at' => now()]);

            return $events->count();
        });
    }
}
