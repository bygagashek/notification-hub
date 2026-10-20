<?php

namespace Tests\Feature;

use App\Channels\PermanentFailure;
use App\Channels\SenderRegistry;
use App\Channels\TransientFailure;
use App\Enums\Channel;
use App\Enums\MessageStatus;
use App\Messaging\BrokerException;
use App\Messaging\DeliveryHandler;
use App\Messaging\Outcome;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InMemoryBroker;
use Tests\Support\ScriptedSender;
use Tests\TestCase;

class DeliveryHandlerTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->broker = new InMemoryBroker();
    }

    public function test_successful_delivery_marks_message_sent(): void
    {
        $message = $this->message();
        $sender = new ScriptedSender();

        $outcome = $this->handler($sender)->handle(['message_id' => $message->id, 'attempt' => 1]);

        $this->assertSame(Outcome::Sent, $outcome);
        $message->refresh();
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertNotNull($message->sent_at);
        $this->assertSame([1], $sender->calls);
        $this->assertSame([], $this->broker->confirmed);
    }

    public function test_redelivery_after_success_is_a_duplicate(): void
    {
        $message = $this->message();
        $sender = new ScriptedSender();
        $handler = $this->handler($sender);

        $handler->handle(['message_id' => $message->id, 'attempt' => 1]);
        $outcome = $handler->handle(['message_id' => $message->id, 'attempt' => 1]);

        $this->assertSame(Outcome::Duplicate, $outcome);
        $this->assertSame([1], $sender->calls, 'the provider must be called once');
    }

    public function test_out_of_order_attempt_is_a_duplicate(): void
    {
        $message = $this->message();
        $sender = new ScriptedSender();

        $outcome = $this->handler($sender)->handle(['message_id' => $message->id, 'attempt' => 3]);

        $this->assertSame(Outcome::Duplicate, $outcome);
        $this->assertSame([], $sender->calls);
    }

    public function test_transient_failure_schedules_next_attempt_on_the_matching_retry_queue(): void
    {
        $message = $this->message();

        $outcome = $this->handler(new ScriptedSender([new TransientFailure('timeout')]))
            ->handle(['message_id' => $message->id, 'attempt' => 1]);

        $this->assertSame(Outcome::Retried, $outcome);
        $this->assertSame([[
            'exchange' => '',
            'routing_key' => 'messages.sms.retry.1',
            'payload' => ['message_id' => $message->id, 'attempt' => 2],
        ]], $this->broker->confirmed);

        $message->refresh();
        $this->assertSame(MessageStatus::Retrying, $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertSame('timeout', $message->last_error);
    }

    public function test_message_is_delivered_after_retries(): void
    {
        $message = $this->message();
        $handler = $this->handler(new ScriptedSender([new TransientFailure('a'), new TransientFailure('b')]));

        $this->assertSame(Outcome::Retried, $handler->handle(['message_id' => $message->id, 'attempt' => 1]));
        $this->assertSame(Outcome::Retried, $handler->handle(['message_id' => $message->id, 'attempt' => 2]));
        $this->assertSame(Outcome::Sent, $handler->handle(['message_id' => $message->id, 'attempt' => 3]));

        $this->assertSame(
            ['messages.sms.retry.1', 'messages.sms.retry.2'],
            array_column($this->broker->confirmed, 'routing_key'),
        );
        $this->assertSame(MessageStatus::Sent, $message->fresh()?->status);
    }

    public function test_exhausted_retries_go_to_dead_letter(): void
    {
        $message = $this->message();
        $failures = array_fill(0, 4, new TransientFailure('timeout'));
        $handler = $this->handler(new ScriptedSender($failures));

        foreach ([1, 2, 3] as $attempt) {
            $this->assertSame(Outcome::Retried, $handler->handle(['message_id' => $message->id, 'attempt' => $attempt]));
        }
        $this->assertSame(Outcome::DeadLettered, $handler->handle(['message_id' => $message->id, 'attempt' => 4]));

        $last = $this->broker->confirmed[3];
        $this->assertSame('messages.dead', $last['exchange']);
        $this->assertSame(4, $last['payload']['attempt']);

        $message->refresh();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertSame('Retries exhausted: timeout', $message->last_error);
    }

    public function test_permanent_failure_skips_retries(): void
    {
        $message = $this->message();

        $outcome = $this->handler(new ScriptedSender([new PermanentFailure('invalid number')]))
            ->handle(['message_id' => $message->id, 'attempt' => 1]);

        $this->assertSame(Outcome::DeadLettered, $outcome);
        $this->assertCount(1, $this->broker->confirmed);
        $this->assertSame('messages.dead', $this->broker->confirmed[0]['exchange']);
        $this->assertSame(MessageStatus::Failed, $message->fresh()?->status);
    }

    public function test_unconfirmed_retry_rolls_back_the_attempt(): void
    {
        $message = $this->message();
        $this->broker->failNextConfirm = true;

        try {
            $this->handler(new ScriptedSender([new TransientFailure('timeout')]))
                ->handle(['message_id' => $message->id, 'attempt' => 1]);
            $this->fail('Expected BrokerException');
        } catch (BrokerException) {
        }

        $message->refresh();
        $this->assertSame(0, $message->attempts, 'redelivery of attempt 1 must still be accepted');
        $this->assertSame(MessageStatus::Pending, $message->status);
    }

    public function test_malformed_payload_is_rejected(): void
    {
        $handler = $this->handler(new ScriptedSender());

        $this->assertSame(Outcome::Rejected, $handler->handle([]));
        $this->assertSame(Outcome::Rejected, $handler->handle(['message_id' => 'x', 'attempt' => '1']));
        $this->assertSame(Outcome::Rejected, $handler->handle(['message_id' => 'missing', 'attempt' => 1]));
    }

    private function handler(ScriptedSender $sender): DeliveryHandler
    {
        $registry = new SenderRegistry();
        foreach (Channel::cases() as $channel) {
            $registry->register($channel, $sender);
        }

        return new DeliveryHandler($registry, $this->broker, 'messages.dead', [5, 30, 120]);
    }

    private function message(): Message
    {
        return Message::query()->create([
            'idempotency_key' => uniqid('key-', true),
            'request_hash' => str_repeat('0', 64),
            'channel' => Channel::Sms,
            'recipient' => '+375291234567',
            'body' => 'hello',
            'status' => MessageStatus::Pending,
        ]);
    }
}
