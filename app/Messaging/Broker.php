<?php

namespace App\Messaging;

interface Broker
{
    /**
     * Queue a persistent message for publishing. Nothing is guaranteed until confirm().
     *
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $exchange, string $routingKey, array $payload): void;

    /**
     * Wait until the broker has accepted every message published since the last call.
     *
     * @throws BrokerException when a message was nacked, returned as unroutable, or the wait timed out
     */
    public function confirm(): void;
}
