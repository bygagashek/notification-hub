<?php

namespace App\Providers;

use App\Channels\LogSender;
use App\Channels\SenderRegistry;
use App\Enums\Channel;
use App\Messaging\AmqpBroker;
use App\Messaging\AmqpConnection;
use App\Messaging\Broker;
use App\Messaging\DeliveryHandler;
use App\Messaging\MessageService;
use App\Messaging\Topology;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AmqpConnection::class, fn () => new AmqpConnection(config('messaging.amqp')));

        $this->app->singleton(Broker::class, fn (Application $app) => new AmqpBroker(
            $app->make(AmqpConnection::class),
            (float) config('messaging.amqp.confirm_timeout'),
        ));

        $this->app->singleton(Topology::class, fn (Application $app) => new Topology(
            $app->make(AmqpConnection::class),
            config('messaging.exchange'),
            config('messaging.dead_letter_exchange'),
            config('messaging.dead_letter_queue'),
            config('messaging.retry_delays'),
        ));

        $this->app->singleton(SenderRegistry::class, function (Application $app): SenderRegistry {
            $registry = new SenderRegistry();
            foreach (Channel::cases() as $channel) {
                // Real providers would be registered here, one per channel.
                $registry->register($channel, $app->make(LogSender::class));
            }

            return $registry;
        });

        $this->app->bind(MessageService::class, fn () => new MessageService(config('messaging.exchange')));

        $this->app->bind(DeliveryHandler::class, fn (Application $app) => new DeliveryHandler(
            $app->make(SenderRegistry::class),
            $app->make(Broker::class),
            config('messaging.dead_letter_exchange'),
            config('messaging.retry_delays'),
        ));
    }
}
