<?php

namespace App\Messaging;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * One TCP connection per process, two channels: publishing in confirm mode and consuming.
 * They are kept apart so waiting for publisher confirms never runs inside a consumer callback
 * on the same channel.
 */
class AmqpConnection
{
    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $publishChannel = null;

    private ?AMQPChannel $consumeChannel = null;

    /**
     * @param  array{host: string, port: int, user: string, password: string, vhost: string}  $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function publishChannel(): AMQPChannel
    {
        return $this->publishChannel ??= $this->connection()->channel();
    }

    public function consumeChannel(): AMQPChannel
    {
        return $this->consumeChannel ??= $this->connection()->channel();
    }

    public function close(): void
    {
        $this->publishChannel?->close();
        $this->consumeChannel?->close();
        $this->connection?->close();
        $this->publishChannel = null;
        $this->consumeChannel = null;
        $this->connection = null;
    }

    private function connection(): AMQPStreamConnection
    {
        return $this->connection ??= new AMQPStreamConnection(
            $this->config['host'],
            $this->config['port'],
            $this->config['user'],
            $this->config['password'],
            $this->config['vhost'],
            heartbeat: 30,
        );
    }
}
