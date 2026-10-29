<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Services;

use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class TransactionalInboxService
{
    /**
     * Checks if a message has already been processed or is currently in progress.
     */
    public function hasBeenProcessed(string $messageId): bool
    {
        return TransactionalInbox::where('message_id', $messageId)
            ->where('status', 'completed')
            ->exists();
    }

    /**
     * Records an incoming message into the inbox if not already received.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordReceived(string $messageId, string $eventType, string $tenantId, array $payload): TransactionalInbox
    {
        return TransactionalInbox::firstOrCreate(
            ['message_id' => $messageId],
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'event_type' => $eventType,
                'payload_json' => $payload,
                'received_at' => now(),
                'status' => 'pending',
            ]
        );
    }

    /**
     * Marks a message as successfully processed.
     */
    public function markCompleted(string $messageId): void
    {
        TransactionalInbox::where('message_id', $messageId)->update([
            'status' => 'completed',
            'processed_at' => now(),
        ]);
    }

    /**
     * Marks a message as failed during processing.
     */
    public function markFailed(string $messageId, string $error): void
    {
        TransactionalInbox::where('message_id', $messageId)->update([
            'status' => 'failed',
        ]);
    }

    /**
     * Executes a callback within a strict idempotent inbox transaction.
     * If the message has already completed, the handler is bypassed.
     *
     * @param  array<string, mixed>  $payload
     */
    public function executeIdempotent(
        string $messageId,
        string $eventType,
        string $tenantId,
        array $payload,
        callable $handler
    ): mixed {
        return DB::transaction(function () use ($messageId, $eventType, $tenantId, $payload, $handler) {
            $inbox = TransactionalInbox::where('message_id', $messageId)
                ->lockForUpdate()
                ->first();

            if ($inbox !== null && $inbox->status === 'completed') {
                return null; // Duplicate message, bypass idempotently
            }

            if ($inbox === null) {
                $inbox = TransactionalInbox::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'message_id' => $messageId,
                    'event_type' => $eventType,
                    'payload_json' => $payload,
                    'received_at' => now(),
                    'status' => 'processing',
                ]);
            } else {
                $inbox->update(['status' => 'processing']);
            }

            try {
                $result = $handler($payload);

                $inbox->update([
                    'status' => 'completed',
                    'processed_at' => now(),
                ]);

                return $result;
            } catch (Throwable $e) {
                $inbox->update(['status' => 'failed']);
                throw $e;
            }
        });
    }
}
