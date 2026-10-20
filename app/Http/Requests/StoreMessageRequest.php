<?php

namespace App\Http\Requests;

use App\Enums\Channel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $recipient = match ($this->input('channel')) {
            Channel::Sms->value => ['regex:/^\+[1-9]\d{9,14}$/'],
            Channel::Email->value => ['email:rfc'],
            Channel::Telegram->value => ['regex:/^-?\d{1,20}$/'],
            default => [],
        };

        return [
            'channel' => ['required', 'string', Rule::enum(Channel::class)],
            'recipient' => ['required', 'string', 'max:255', ...$recipient],
            'body' => ['required', 'string', 'max:2000'],
        ];
    }
}
