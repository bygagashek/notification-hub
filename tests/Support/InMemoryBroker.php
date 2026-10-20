<?php

namespace Tests\Support;

use App\Messaging\Broker;
use App\Messaging\BrokerException;

class InMemoryBroker implements Broker
{
    /** @var list<array{exchange: string, routing_key: string, payload: array<string, mixed>}> */
    public array $confirmed = [];

    /** @var list<array{exchange: string, routing_key: string, payload: array<string, mixed>}> */
    private array $pending = [];

    public bool $failNextConfirm = false;

    public function publish(string $exchange, string $routingKey, array $payload): void
    {
        $this->pending[] = ['exchange' => $exchange, 'routing_key' => $routingKey, 'payload' => $payload];
    }

    public function confirm(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        if ($this->failNextConfirm) {
            $this->failNextConfirm = false;

            throw new BrokerException('nacked');
        }

        array_push($this->confirmed, ...$pending);
    }
}
