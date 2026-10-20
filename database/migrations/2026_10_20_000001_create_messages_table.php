<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('idempotency_key', 100)->unique();
            $table->char('request_hash', 64);
            $table->string('channel', 20);
            $table->string('recipient', 255);
            $table->text('body');
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('outbox', function (Blueprint $table) {
            $table->id();
            $table->string('exchange', 100);
            $table->string('routing_key', 100);
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('published_at')->nullable();

            $table->index(['published_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
        Schema::dropIfExists('messages');
    }
};
