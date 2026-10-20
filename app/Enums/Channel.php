<?php

namespace App\Enums;

enum Channel: string
{
    case Sms = 'sms';
    case Email = 'email';
    case Telegram = 'telegram';

    public function queue(): string
    {
        return 'messages.'.$this->value;
    }

    public function retryQueue(int $step): string
    {
        return 'messages.'.$this->value.'.retry.'.$step;
    }
}
