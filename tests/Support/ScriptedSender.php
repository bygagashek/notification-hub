<?php

namespace Tests\Support;

use App\Channels\ChannelSender;
use App\Models\Message;
use Throwable;

/**
 * Throws the queued exceptions one per call, then succeeds.
 */
class ScriptedSender implements ChannelSender
{
    /** @var list<int> attempt numbers seen by send() */
    public array $calls = [];

    /**
     * @param  list<Throwable>  $failures
     */
    public function __construct(private array $failures = [])
    {
    }

    public function send(Message $message): void
    {
        $this->calls[] = $message->attempts;

        $failure = array_shift($this->failures);
        if ($failure !== null) {
            throw $failure;
        }
    }
}
