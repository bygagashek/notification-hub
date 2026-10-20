<?php

namespace Tests\Integration;

use App\Enums\Channel;
use App\Enums\MessageStatus;
use App\Messaging\AmqpConnection;
use App\Messaging\Broker;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Runs the whole path against a real RabbitMQ: API -> outbox -> relay -> queue -> consumer.
 * Skipped when no broker is reachable at RABBITMQ_HOST:RABBITMQ_PORT.
 */
class DeliveryPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $host = (string) config('messaging.amqp.host');
        $port = (int) config('messaging.amqp.port');
        $socket = @fsockopen($host, $port, $errno, $error, 1.0);
        if ($socket === false) {
            $this->markTestSkipped("RabbitMQ is not reachable at {$host}:{$port}");
        }
        fclose($socket);

        // Short delays keep the retry path fast; the topology must be declared with the same values.
        config(['messaging.retry_delays' => [1, 1, 1]]);

        $this->assertSame(0, Artisan::call('amqp:setup'));
        $this->purgeQueues();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->app->make(AmqpConnection::class)->close();
        }

        parent::tearDown();
    }

    public function test_message_is_delivered_end_to_end(): void
    {
        $id = $this->send('Your table is booked');

        $this->relayAndConsume();

        $message = Message::query()->findOrFail($id);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame(1, $message->attempts);
    }

    public function test_flaky_message_comes_back_through_retry_queues(): void
    {
        $id = $this->send('[flaky] Your table is booked');

        $this->relayAndConsume();

        $message = Message::query()->findOrFail($id);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame(3, $message->attempts);
    }

    public function test_rejected_message_lands_in_dead_letter_queue(): void
    {
        $id = $this->send('[reject] Your table is booked');

        $this->relayAndConsume();

        $this->assertSame(MessageStatus::Failed, Message::query()->findOrFail($id)->status);

        $dead = $this->app->make(AmqpConnection::class)->consumeChannel()->basic_get('messages.dead', true);
        $this->assertNotNull($dead);
        $this->assertSame(
            ['message_id' => $id, 'attempt' => 1, 'error' => 'Provider rejected the recipient'],
            json_decode($dead->getBody(), true),
        );
    }

    public function test_duplicate_delivery_is_processed_once(): void
    {
        $id = $this->send('Your table is booked');
        $this->assertSame(0, Artisan::call('outbox:relay', ['--once' => true]));

        // Simulate a redelivery, e.g. after a consumer crashed before acking.
        $broker = $this->app->make(Broker::class);
        $broker->publish('messages', Channel::Sms->value, ['message_id' => $id, 'attempt' => 1]);
        $broker->confirm();

        Artisan::call('messages:consume', ['channel' => 'sms', '--idle-timeout' => 3]);
        $output = Artisan::output();

        $this->assertSame(1, substr_count($output, 'sent '));
        $this->assertSame(1, substr_count($output, 'duplicate '));
        $this->assertSame(1, Message::query()->findOrFail($id)->attempts);
    }

    private function send(string $body): string
    {
        return (string) $this->postJson('/api/messages', [
            'channel' => 'sms',
            'recipient' => '+375291234567',
            'body' => $body,
        ], ['Idempotency-Key' => uniqid('it-', true)])->assertStatus(202)->json('data.id');
    }

    private function relayAndConsume(): void
    {
        $this->assertSame(0, Artisan::call('outbox:relay', ['--once' => true]));

        // Exits once the queue has been quiet for 3 s, which is longer than any retry delay here.
        Artisan::call('messages:consume', ['channel' => 'sms', '--idle-timeout' => 3]);
    }

    private function purgeQueues(): void
    {
        $channel = $this->app->make(AmqpConnection::class)->consumeChannel();
        $channel->queue_purge('messages.dead');
        foreach (Channel::cases() as $target) {
            $channel->queue_purge($target->queue());
            foreach ([1, 2, 3] as $step) {
                $channel->queue_purge($target->retryQueue($step));
            }
        }
    }
}
