<?php

namespace App\Console\Commands;

use App\Messaging\Topology;
use Illuminate\Console\Command;

class SetupTopology extends Command
{
    protected $signature = 'amqp:setup';

    protected $description = 'Declare RabbitMQ exchanges and queues';

    public function handle(Topology $topology): int
    {
        $topology->declare();
        $this->info('Topology declared.');

        return self::SUCCESS;
    }
}
