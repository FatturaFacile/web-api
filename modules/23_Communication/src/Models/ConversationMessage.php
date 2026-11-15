<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One inbound or outbound message of a conversation. Outbound messages point at the delivery log of the attempt.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property string $direction
 * @property string $channel
 * @property string|null $sender_address
 * @property string|null $sender_user_id
 * @property string $body
 * @property string|null $delivery_log_id
 * @property string|null $delivery_status
 * @property Carbon $occurred_at
 */
final class ConversationMessage extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Directions = ['inbound', 'outbound'];

    protected $table = 'comm_conversation_messages';

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<CommunicationConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CommunicationConversation::class, 'conversation_id');
    }
}
