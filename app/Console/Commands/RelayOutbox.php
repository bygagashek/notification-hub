<?php

namespace App\Console\Commands;

use App\Messaging\OutboxRelay;
use Illuminate\Console\Command;
use Throwable;

class RelayOutbox extends Command
{
    protected $signature = 'outbox:relay
        {--once : Relay what is pending and exit}
        {--idle-sleep=500 : Milliseconds to sleep when the outbox is empty}';

    protected $description = 'Publish committed outbox events to RabbitMQ';

    private bool $stopping = false;

    public function handle(OutboxRelay $relay): int
    {
        $batch = (int) config('messaging.relay_batch');
        $idleSleep = max(10, (int) $this->option('idle-sleep'));

        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], function (): void {
                $this->stopping = true;
            });
        }

        do {
            try {
                $published = $relay->relayBatch($batch);
            } catch (Throwable $e) {
                // Nothing was marked published: the batch is retried on the next pass.
                report($e);
                $this->error('Relay failed: '.$e->getMessage());
                if ($this->option('once')) {
                    return self::FAILURE;
                }
                usleep(2_000_000);

                continue;
            }

            if ($published > 0) {
                $this->line("Published {$published} event(s).");

                continue;
            }

            if ($this->option('once')) {
                break;
            }
            usleep($idleSleep * 1000);
        } while (! $this->stopping);

        return self::SUCCESS;
    }
}
