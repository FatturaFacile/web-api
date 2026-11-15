<?php

declare(strict_types=1);

namespace Modules\Communication\Services;

use App\Models\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Communication\DTOs\DeliveryMessage;
use Modules\Communication\Models\CommunicationConversation;
use Modules\Communication\Models\ConversationMessage;

/**
 * The unified inbox: inbound messages of every channel are aggregated into conversations (one open thread per channel
 * and counterparty), agents are assigned, replies leave through the normal delivery path and are logged.
 */
final readonly class InboxService
{
    public function __construct(private MessageDispatcher $dispatcher) {}

    /**
     * Records an inbound message, opening a conversation or reusing the not closed one of the counterparty.
     *
     * @param  array{channel: string, address: string, name?: ?string, subject?: ?string, body: string, received_at?: ?string}  $data
     */
    public function receive(string $tenantId, array $data): ConversationMessage
    {
        return DB::transaction(function () use ($tenantId, $data): ConversationMessage {
            $address = mb_strtolower(trim($data['address']));
            $conversation = CommunicationConversation::query()
                ->where('tenant_id', $tenantId)
                ->where('channel', $data['channel'])
                ->where('counterparty_address', $address)
                ->where('status', '!=', 'closed')
                ->latest('last_message_at')
                ->first();

            $when = isset($data['received_at']) ? Carbon::parse($data['received_at']) : now();

            if ($conversation === null) {
                $conversation = CommunicationConversation::create([
                    'tenant_id' => $tenantId,
                    'channel' => $data['channel'],
                    'counterparty_address' => $address,
                    'counterparty_name' => $data['name'] ?? null,
                    'contact_id' => $this->contactIdFor($tenantId, $data['channel'], $address),
                    'subject' => $data['subject'] ?? null,
                    'status' => 'open',
                ]);
            }

            $message = ConversationMessage::create([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'channel' => $data['channel'],
                'sender_address' => $address,
                'body' => $data['body'],
                'occurred_at' => $when,
            ]);

            // A counterparty answering a resolved or pending thread reopens it.
            $conversation->forceFill([
                'status' => in_array($conversation->status, ['resolved', 'pending'], true) ? 'open' : $conversation->status,
                'resolved_at' => null,
                'unread_count' => $conversation->unread_count + 1,
                'last_message_at' => $when,
            ])->save();

            return $message;
        });
    }

    /**
     * Sends a reply on the channel of the conversation. The provider call happens outside any transaction.
     */
    public function reply(CommunicationConversation $conversation, string $body, ?string $userId): ConversationMessage
    {
        if ($conversation->status === 'closed') {
            throw ValidationException::withMessages(['status' => __('communication_errors.conversation.closed')]);
        }

        $settings = $this->dispatcher->settings($conversation->channel);
        $log = $this->dispatcher->dispatch(
            $conversation->channel,
            new DeliveryMessage($conversation->channel, $conversation->counterparty_address, (string) ($conversation->subject ?? ''), $body),
            ['message_type' => 'conversation', 'sent_by' => $userId, 'tenant_id' => $conversation->tenant_id],
            $settings,
        );

        return DB::transaction(function () use ($conversation, $body, $userId, $log): ConversationMessage {
            $message = ConversationMessage::create([
                'tenant_id' => $conversation->tenant_id,
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'channel' => $conversation->channel,
                'sender_user_id' => $userId,
                'body' => $body,
                'delivery_log_id' => $log->id,
                'delivery_status' => $log->status,
                'occurred_at' => now(),
            ]);

            $conversation->forceFill([
                'status' => $conversation->status === 'open' ? 'pending' : $conversation->status,
                'unread_count' => 0,
                'last_message_at' => now(),
            ])->save();

            return $message;
        });
    }

    public function assign(CommunicationConversation $conversation, ?string $userId): CommunicationConversation
    {
        $conversation->forceFill(['assigned_to' => $userId, 'assigned_at' => $userId === null ? null : now()])->save();

        return $conversation;
    }

    public function changeStatus(CommunicationConversation $conversation, string $status): CommunicationConversation
    {
        $conversation->forceFill(['status' => $status, 'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? now() : null])->save();

        return $conversation;
    }

    public function markRead(CommunicationConversation $conversation): CommunicationConversation
    {
        $conversation->forceFill(['unread_count' => 0])->save();

        return $conversation;
    }

    private function contactIdFor(string $tenantId, string $channel, string $address): ?string
    {
        $column = $channel === 'email' ? 'email' : 'phone';
        $id = Contact::query()->where('tenant_id', $tenantId)->where($column, $address)->value('id');

        return $id === null ? null : (string) $id;
    }
}
