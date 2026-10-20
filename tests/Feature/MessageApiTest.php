<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MessageApiTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = ['channel' => 'sms', 'recipient' => '+375291234567', 'body' => 'Your table is booked for 19:00'];

    public function test_creates_message_and_outbox_event_together(): void
    {
        $response = $this->postJson('/api/messages', self::PAYLOAD, ['Idempotency-Key' => 'order-1']);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.attempts', 0);

        $id = $response->json('data.id');
        $this->assertDatabaseCount('messages', 1);

        $event = OutboxEvent::query()->sole();
        $this->assertSame('messages', $event->exchange);
        $this->assertSame('sms', $event->routing_key);
        $this->assertSame(['message_id' => $id, 'attempt' => 1], $event->payload);
        $this->assertNull($event->published_at);
    }

    public function test_repeated_request_with_same_key_returns_original(): void
    {
        $first = $this->postJson('/api/messages', self::PAYLOAD, ['Idempotency-Key' => 'order-1']);
        $second = $this->postJson('/api/messages', self::PAYLOAD, ['Idempotency-Key' => 'order-1']);

        $second->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('outbox', 1);
    }

    public function test_same_key_with_different_body_is_a_conflict(): void
    {
        $this->postJson('/api/messages', self::PAYLOAD, ['Idempotency-Key' => 'order-1']);

        $this->postJson('/api/messages', ['body' => 'Something else'] + self::PAYLOAD, ['Idempotency-Key' => 'order-1'])
            ->assertStatus(409);

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_idempotency_key_is_required(): void
    {
        $this->postJson('/api/messages', self::PAYLOAD)->assertStatus(400);

        $this->assertDatabaseCount('messages', 0);
    }

    /**
     * @param  array<string, string>  $override
     */
    #[DataProvider('invalidPayloads')]
    public function test_rejects_invalid_payload(array $override, string $field): void
    {
        $this->postJson('/api/messages', $override + self::PAYLOAD, ['Idempotency-Key' => 'k'])
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'unknown channel' => [['channel' => 'pigeon'], 'channel'],
            'sms to an email' => [['recipient' => 'guest@example.com'], 'recipient'],
            'email to a phone' => [['channel' => 'email', 'recipient' => '+375291234567'], 'recipient'],
            'telegram to a name' => [['channel' => 'telegram', 'recipient' => 'guest'], 'recipient'],
            'empty body' => [['body' => ''], 'body'],
        ];
    }

    public function test_shows_message_status(): void
    {
        $id = $this->postJson('/api/messages', self::PAYLOAD, ['Idempotency-Key' => 'order-1'])->json('data.id');

        $this->getJson("/api/messages/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.channel', 'sms');
    }

    public function test_unknown_message_is_404(): void
    {
        $this->getJson('/api/messages/01JAAAAAAAAAAAAAAAAAAAAAAA')->assertNotFound();
    }

    public function test_message_id_is_a_ulid(): void
    {
        $id = $this->postJson('/api/messages', self::PAYLOAD, ['Idempotency-Key' => 'order-1'])->json('data.id');

        $this->assertTrue(Message::query()->whereKey($id)->exists());
        $this->assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/', (string) $id);
    }
}
