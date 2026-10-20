<?php

namespace App\Channels;

use App\Enums\Channel;
use LogicException;

class SenderRegistry
{
    /** @var array<string, ChannelSender> */
    private array $senders = [];

    public function register(Channel $channel, ChannelSender $sender): void
    {
        $this->senders[$channel->value] = $sender;
    }

    public function for(Channel $channel): ChannelSender
    {
        return $this->senders[$channel->value]
            ?? throw new LogicException("No sender registered for channel {$channel->value}");
    }
}
