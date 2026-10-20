<?php

namespace App\Console\Commands;

use App\Enums\Channel;
use App\Messaging\AmqpConnection;
use App\Messaging\DeliveryHandler;
use App\Messaging\Outcome;
use Illuminate\Console\Command;
use JsonException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class ConsumeMessages extends Command
{
    protected $signature = 'messages:consume
        {channel : sms, email or telegram}
        {--max-messages=0 : Exit after this many deliveries (0 = run forever)}
        {--idle-timeout=0 : Exit after this many seconds without deliveries (0 = never)}';

    protected $description = 'Consume delivery attempts for one channel';

    private bool $stopping = false;

    private int $handled = 0;

    public function handle(AmqpConnection $connection, DeliveryHandler $handler): int
    {
        $channel = Channel::tryFrom((string) $this->argument('channel'));
        if ($channel === null) {
            $this->error('Unknown channel.');

            return self::INVALID;
        }

        $maxMessages = (int) $this->option('max-messages');
        $idleTimeout = (int) $this->option('idle-timeout');

        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], function (): void {
                $this->stopping = true;
            });
        }

        $amqp = $connection->consumeChannel();
        // Prefetch bounds the number of unacked deliveries this worker holds at once.
        $amqp->basic_qos(0, (int) config('messaging.prefetch'), false);
        $amqp->basic_consume($channel->queue(), '', false, false, false, false,
            function (AMQPMessage $delivery) use ($handler, $maxMessages): void {
                $this->process($handler, $delivery);
                if ($maxMessages > 0 && ++$this->handled >= $maxMessages) {
                    $this->stopping = true;
                }
            });

        $this->info("Consuming {$channel->queue()}");

        while (! $this->stopping && $amqp->is_consuming()) {
            try {
                // Wake up at least once a second so signals and limits are noticed.
                $amqp->wait(null, false, $idleTimeout > 0 ? $idleTimeout : 1);
            } catch (AMQPTimeoutException) {
                if ($idleTimeout > 0) {
                    break;
                }
            }
        }

        $connection->close();

        return self::SUCCESS;
    }

    private function process(DeliveryHandler $handler, AMQPMessage $delivery): void
    {
        try {
            $payload = json_decode($delivery->getBody(), true, 8, JSON_THROW_ON_ERROR);
            $outcome = is_array($payload) ? $handler->handle($payload) : Outcome::Rejected;
        } catch (JsonException) {
            $outcome = Outcome::Rejected;
        } catch (Throwable $e) {
            // Database or broker trouble: hand the delivery back and slow down.
            report($e);
            $this->error('Delivery failed, requeueing: '.$e->getMessage());
            sleep(1);
            $delivery->nack(true);

            return;
        }

        if ($outcome === Outcome::Rejected) {
            $delivery->nack(false);
        } else {
            $delivery->ack();
        }

        $this->line($outcome->value.' '.$delivery->getBody());
    }
}
