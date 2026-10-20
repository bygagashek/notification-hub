<?php

namespace App\Channels;

use App\Models\Message;
use Psr\Log\LoggerInterface;

/**
 * Stand-in for a real provider: writes the message to the log.
 *
 * Two markers in the body make failures reproducible in the demo:
 *   [flaky]  - fails transiently on the first two attempts
 *   [reject] - fails permanently
 */
class LogSender implements ChannelSender
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function send(Message $message): void
    {
        if (str_contains($message->body, '[reject]')) {
            throw new PermanentFailure('Provider rejected the recipient');
        }
        if (str_contains($message->body, '[flaky]') && $message->attempts < 3) {
            throw new TransientFailure('Provider timed out');
        }

        $this->logger->info('Message delivered', [
            'id' => $message->id,
            'channel' => $message->channel->value,
            'recipient' => $message->recipient,
            'attempt' => $message->attempts,
        ]);
    }
}
