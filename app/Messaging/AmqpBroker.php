<?php

namespace App\Messaging;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

class AmqpBroker implements Broker
{
    private ?AMQPChannel $channel = null;

    /** @var list<string> */
    private array $failures = [];

    public function __construct(
        private readonly AmqpConnection $connection,
        private readonly float $confirmTimeout,
    ) {
    }

    public function publish(string $exchange, string $routingKey, array $payload): void
    {
        $properties = [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];
        if (isset($payload['message_id']) && is_string($payload['message_id'])) {
            $properties['message_id'] = $payload['message_id'];
        }

        $message = new AMQPMessage(json_encode($payload, JSON_THROW_ON_ERROR), $properties);

        // mandatory=true: an unroutable message comes back as basic.return instead of vanishing.
        $this->channel()->basic_publish($message, $exchange, $routingKey, true);
    }

    public function confirm(): void
    {
        try {
            $this->channel()->wait_for_pending_acks_returns($this->confirmTimeout);
        } catch (AMQPTimeoutException) {
            $this->failures[] = 'confirm timed out after '.$this->confirmTimeout.'s';
        }

        if ($this->failures !== []) {
            $failures = $this->failures;
            $this->failures = [];

            throw new BrokerException('Broker did not accept messages: '.implode('; ', $failures));
        }
    }

    private function channel(): AMQPChannel
    {
        // The connection may have been closed and reopened since the last publish.
        if ($this->channel !== null && $this->channel->is_open()) {
            return $this->channel;
        }

        $channel = $this->connection->publishChannel();
        $this->failures = [];
        $channel->confirm_select();
        $channel->set_nack_handler(function (AMQPMessage $message): void {
            $this->failures[] = 'nacked '.$message->getRoutingKey();
        });
        $channel->set_return_listener(function (int $code, string $text, string $exchange, string $routingKey): void {
            $this->failures[] = "returned {$code} {$text} ({$exchange} -> {$routingKey})";
        });

        return $this->channel = $channel;
    }
}
