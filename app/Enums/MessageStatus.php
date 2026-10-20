<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Pending = 'pending';
    case Retrying = 'retrying';
    case Sent = 'sent';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Sent || $this === self::Failed;
    }
}
