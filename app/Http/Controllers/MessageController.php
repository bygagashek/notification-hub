<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Messaging\IdempotencyConflict;
use App\Messaging\MessageService;
use App\Models\Message;
use Illuminate\Http\JsonResponse;

class MessageController extends Controller
{
    public function __construct(private readonly MessageService $messages)
    {
    }

    public function store(StoreMessageRequest $request): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if ($key === '' || strlen($key) > 100) {
            return response()->json(['message' => 'Idempotency-Key header is required (1-100 characters).'], 400);
        }

        /** @var array{channel: string, recipient: string, body: string} $data */
        $data = $request->validated();

        try {
            $result = $this->messages->create($key, $data);
        } catch (IdempotencyConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return (new MessageResource($result->message))
            ->response()
            ->setStatusCode($result->isNew ? 202 : 200);
    }

    public function show(Message $message): MessageResource
    {
        return new MessageResource($message);
    }
}
