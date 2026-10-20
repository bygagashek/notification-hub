<?php

return [

    'amqp' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'confirm_timeout' => (float) env('RABBITMQ_CONFIRM_TIMEOUT', 5),
    ],

    // Work exchange: routing key = channel, one queue per channel.
    'exchange' => 'messages',

    // Messages that will never be delivered: permanent errors, retries exhausted, malformed.
    'dead_letter_exchange' => 'messages.dead',
    'dead_letter_queue' => 'messages.dead',

    'channels' => ['sms', 'email', 'telegram'],

    // Delay in seconds before attempt N+1. Each step gets its own queue with a fixed TTL,
    // so a long delay never blocks a short one at the head of a queue.
    'retry_delays' => array_map('intval', explode(',', (string) env('MESSAGING_RETRY_DELAYS', '5,30,120'))),

    'prefetch' => (int) env('CONSUMER_PREFETCH', 10),

    'relay_batch' => (int) env('OUTBOX_RELAY_BATCH', 100),

];
