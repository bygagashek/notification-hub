<?php

namespace App\Messaging;

enum Outcome: string
{
    case Sent = 'sent';
    case Retried = 'retried';
    case DeadLettered = 'dead_lettered';
    // Redelivery of an attempt that was already processed: acknowledge and drop.
    case Duplicate = 'duplicate';
    // Payload is unusable: reject without requeue, the queue dead-letters it.
    case Rejected = 'rejected';
}
