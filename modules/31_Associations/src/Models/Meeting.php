<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Associations\Enums\MeetingBody;
use Modules\Associations\Enums\MeetingStatus;

/**
 * A meeting of the assembly, of the board or of another body, with its notice, agenda and participants.
 *
 * @property string $id
 * @property MeetingBody $body
 * @property MeetingStatus $status
 * @property bool $proxies_allowed
 * @property ?string $first_call_quorum_percent
 * @property ?string $second_call_quorum_percent
 */
final class Meeting extends AssociationRecord
{
    protected $table = 'assoc_meetings';

    protected function casts(): array
    {
        return [
            'body' => MeetingBody::class,
            'status' => MeetingStatus::class,
            'scheduled_at' => 'datetime',
            'second_call_at' => 'datetime',
            'convened_at' => 'datetime',
            'held_at' => 'datetime',
            'closed_at' => 'datetime',
            'proxies_allowed' => 'boolean',
            'first_call_quorum_percent' => 'decimal:2',
            'second_call_quorum_percent' => 'decimal:2',
        ];
    }

    public function agendaItems(): HasMany
    {
        return $this->hasMany(MeetingAgendaItem::class, 'meeting_id')->orderBy('position');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MeetingAttachment::class, 'meeting_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class, 'meeting_id');
    }

    public function ballots(): HasMany
    {
        return $this->hasMany(Ballot::class, 'meeting_id');
    }

    public function minutes(): HasOne
    {
        return $this->hasOne(Minutes::class, 'meeting_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [MeetingStatus::Draft, MeetingStatus::Convened], true);
    }

    public function bodyLabel(): string
    {
        return $this->body === MeetingBody::Other && $this->body_name ? (string) $this->body_name : (string) __('association_governance.body.'.$this->body->value);
    }
}
