<?php

namespace App\Messaging;

use App\Channels\PermanentFailure;
use App\Channels\SenderRegistry;
use App\Channels\TransientFailure;
use App\Enums\MessageStatus;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Processes one delivery attempt. Broker-agnostic: the consumer command maps the Outcome
 * to ack / reject.
 *
 * Every AMQP message carries {message_id, attempt}. The row is locked and the attempt is
 * processed only if it is the next one expected (attempts == attempt - 1). Duplicates from
 * relay republishing or broker redelivery therefore become no-ops.
 */
class DeliveryHandler
{
    /**
     * @param  list<int>  $retryDelays
     */
    public function __construct(
        private readonly SenderRegistry $senders,
        private readonly Broker $broker,
        private readonly string $deadLetterExchange,
        private readonly array $retryDelays,
    ) {
    }

    /**
     * @param  array<mixed>  $payload
     */
    public function handle(array $payload): Outcome
    {
        $id = $payload['message_id'] ?? null;
        $attempt = $payload['attempt'] ?? null;
        if (! is_string($id) || ! is_int($attempt) || $attempt < 1) {
            return Outcome::Rejected;
        }

        return DB::transaction(function () use ($id, $attempt): Outcome {
            $message = Message::query()->lockForUpdate()->find($id);
            if ($message === null) {
                return Outcome::Rejected;
            }
            if ($message->status->isFinal() || $message->attempts !== $attempt - 1) {
                return Outcome::Duplicate;
            }

            $message->attempts = $attempt;

            try {
                $this->senders->for($message->channel)->send($message);
            } catch (PermanentFailure $e) {
                return $this->deadLetter($message, $e->getMessage());
            } catch (TransientFailure $e) {
                return $this->retry($message, $e->getMessage());
            }

            $message->status = MessageStatus::Sent;
            $message->sent_at = now();
            $message->last_error = null;
            $message->save();

            return Outcome::Sent;
        });
    }

    private function retry(Message $message, string $error): Outcome
    {
        $step = $message->attempts;
        if ($step > count($this->retryDelays)) {
            return $this->deadLetter($message, 'Retries exhausted: '.$error);
        }

        // Publish and confirm before the row is committed. If the commit then fails, the
        // original delivery is redelivered and the orphaned retry is dropped as a duplicate.
        $this->broker->publish('', $message->channel->retryQueue($step), [
            'message_id' => $message->id,
            'attempt' => $message->attempts + 1,
        ]);
        $this->broker->confirm();

        $message->status = MessageStatus::Retrying;
        $message->last_error = $error;
        $message->save();

        return Outcome::Retried;
    }

    private function deadLetter(Message $message, string $error): Outcome
    {
        $this->broker->publish($this->deadLetterExchange, $message->channel->value, [
            'message_id' => $message->id,
            'attempt' => $message->attempts,
            'error' => $error,
        ]);
        $this->broker->confirm();

        $message->status = MessageStatus::Failed;
        $message->last_error = mb_substr($error, 0, 500);
        $message->save();

        return Outcome::DeadLettered;
    }
}
