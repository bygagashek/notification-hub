<?php

namespace App\Channels;

use App\Models\Message;

interface ChannelSender
{
    /**
     * @throws TransientFailure when a later attempt may succeed (timeout, 5xx, rate limit)
     * @throws PermanentFailure when retrying is pointless (invalid recipient, blocked)
     */
    public function send(Message $message): void;
}
