<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A thread with one counterparty on one channel in the unified inbox. Inbound and outbound messages of the same
 * counterparty and channel are aggregated while the conversation is not closed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $channel
 * @property string $counterparty_address
 * @property string|null $counterparty_name
 * @property string|null $contact_id
 * @property string|null $subject
 * @property string $status
 * @property string|null $assigned_to
 * @property Carbon|null $assigned_at
 * @property int $unread_count
 * @property Carbon|null $last_message_at
 * @property Carbon|null $resolved_at
 */
final class CommunicationConversation extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Statuses = ['open', 'pending', 'resolved', 'closed'];

    protected $table = 'comm_conversations';

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'last_message_at' => 'datetime',
            'resolved_at' => 'datetime',
            'unread_count' => 'integer',
        ];
    }

    /**
     * @return HasMany<ConversationMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class, 'conversation_id');
    }
}
