<?php

namespace App\Messaging;

use App\Enums\Channel;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Declares exchanges and queues. Idempotent: safe to run on every deploy.
 *
 *   messages (direct) -- sms --> messages.sms -- reject --> messages.dead
 *   messages.sms.retry.N: TTL = delay N, then dead-lettered back to "messages" with key "sms"
 */
class Topology
{
    /**
     * @param  list<int>  $retryDelays
     */
    public function __construct(
        private readonly AmqpConnection $connection,
        private readonly string $exchange,
        private readonly string $deadLetterExchange,
        private readonly string $deadLetterQueue,
        private readonly array $retryDelays,
    ) {
    }

    public function declare(): void
    {
        $channel = $this->connection->publishChannel();

        $channel->exchange_declare($this->exchange, 'direct', false, true, false);
        $channel->exchange_declare($this->deadLetterExchange, 'fanout', false, true, false);

        $channel->queue_declare($this->deadLetterQueue, false, true, false, false, false, self::quorum());
        $channel->queue_bind($this->deadLetterQueue, $this->deadLetterExchange);

        foreach (Channel::cases() as $target) {
            $channel->queue_declare($target->queue(), false, true, false, false, false, self::quorum([
                'x-dead-letter-exchange' => $this->deadLetterExchange,
            ]));
            $channel->queue_bind($target->queue(), $this->exchange, $target->value);

            foreach ($this->retryDelays as $index => $delay) {
                // Retry queues have no consumers: a message waits out the TTL and goes back to work.
                $channel->queue_declare($target->retryQueue($index + 1), false, true, false, false, false, self::quorum([
                    'x-message-ttl' => $delay * 1000,
                    'x-dead-letter-exchange' => $this->exchange,
                    'x-dead-letter-routing-key' => $target->value,
                ]));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function quorum(array $arguments = []): AMQPTable
    {
        return new AMQPTable(['x-queue-type' => 'quorum'] + $arguments);
    }
}
