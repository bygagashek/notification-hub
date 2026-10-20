<?php

namespace Tests\Unit;

use App\Enums\Channel;
use PHPUnit\Framework\TestCase;

class ChannelTest extends TestCase
{
    public function test_queue_names(): void
    {
        $this->assertSame('messages.email', Channel::Email->queue());
        $this->assertSame('messages.telegram.retry.2', Channel::Telegram->retryQueue(2));
    }
}
