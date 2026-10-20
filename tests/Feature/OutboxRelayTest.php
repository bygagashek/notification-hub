<?php

namespace Tests\Feature;

use App\Messaging\BrokerException;
use App\Messaging\OutboxRelay;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InMemoryBroker;
use Tests\TestCase;

class OutboxRelayTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryBroker $broker;

    private OutboxRelay $relay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->broker = new InMemoryBroker();
        $this->relay = new OutboxRelay($this->broker);
    }

    public function test_publishes_pending_events_in_order_and_marks_them(): void
    {
        $first = $this->event('sms', 'a');
        $second = $this->event('email', 'b');

        $this->assertSame(2, $this->relay->relayBatch(100));

        $this->assertSame(['a', 'b'], array_map(fn ($m) => $m['payload']['message_id'], $this->broker->confirmed));
        $this->assertSame('email', $this->broker->confirmed[1]['routing_key']);
        $this->assertNotNull($first->fresh()?->published_at);
        $this->assertNotNull($second->fresh()?->published_at);
    }

    public function test_published_events_are_not_sent_again(): void
    {
        $this->event('sms', 'a');
        $this->relay->relayBatch(100);

        $this->assertSame(0, $this->relay->relayBatch(100));
        $this->assertCount(1, $this->broker->confirmed);
    }

    public function test_respects_batch_size(): void
    {
        $this->event('sms', 'a');
        $this->event('sms', 'b');
        $this->event('sms', 'c');

        $this->assertSame(2, $this->relay->relayBatch(2));
        $this->assertSame(1, $this->relay->relayBatch(2));
    }

    public function test_unconfirmed_batch_stays_pending(): void
    {
        $event = $this->event('sms', 'a');
        $this->broker->failNextConfirm = true;

        try {
            $this->relay->relayBatch(100);
            $this->fail('Expected BrokerException');
        } catch (BrokerException) {
        }

        $this->assertNull($event->fresh()?->published_at);

        $this->assertSame(1, $this->relay->relayBatch(100));
        $this->assertNotNull($event->fresh()?->published_at);
    }

    private function event(string $routingKey, string $messageId): OutboxEvent
    {
        return OutboxEvent::query()->create([
            'exchange' => 'messages',
            'routing_key' => $routingKey,
            'payload' => ['message_id' => $messageId, 'attempt' => 1],
        ]);
    }
}
