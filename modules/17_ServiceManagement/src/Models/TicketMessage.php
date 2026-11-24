<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ServiceManagement\Enums\MessageVisibility;

/**
 * One message of the conversation of a ticket: a public reply, seen by the customer, or an internal note.
 */
final class TicketMessage extends BaseEnterpriseModel
{
    protected $table = 'esm_ticket_messages';

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @param  Builder<TicketMessage>  $query
     * @return Builder<TicketMessage>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('visibility', MessageVisibility::Public->value);
    }

    public function isInternal(): bool
    {
        return $this->visibility === MessageVisibility::Internal->value;
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * @return HasMany<TicketAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'message_id');
    }
}
