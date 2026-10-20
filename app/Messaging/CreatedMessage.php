<?php

namespace App\Messaging;

use App\Models\Message;

final class CreatedMessage
{
    public function __construct(
        public readonly Message $message,
        public readonly bool $isNew,
    ) {
    }
}
