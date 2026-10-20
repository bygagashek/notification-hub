<?php

namespace Tests\Unit;

use App\Channels\LogSender;
use App\Channels\PermanentFailure;
use App\Channels\TransientFailure;
use App\Enums\Channel;
use App\Models\Message;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class LogSenderTest extends TestCase
{
    public function test_flaky_message_fails_twice_then_succeeds(): void
    {
        $sender = new LogSender(new NullLogger());

        foreach ([1, 2] as $attempt) {
            try {
                $sender->send($this->message('[flaky] hi', $attempt));
                $this->fail("attempt {$attempt} should fail");
            } catch (TransientFailure) {
            }
        }

        $sender->send($this->message('[flaky] hi', 3));
        $this->addToAssertionCount(1);
    }

    public function test_rejected_message_fails_permanently(): void
    {
        $this->expectException(PermanentFailure::class);

        (new LogSender(new NullLogger()))->send($this->message('[reject] hi', 1));
    }

    private function message(string $body, int $attempts): Message
    {
        $message = new Message(['channel' => Channel::Sms, 'recipient' => '+375291234567', 'body' => $body]);
        $message->attempts = $attempts;

        return $message;
    }
}
