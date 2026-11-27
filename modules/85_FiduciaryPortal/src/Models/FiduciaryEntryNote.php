<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Models;

use App\Domain\Audit\Concerns\HasImmutableAuditTrail;
use App\Domain\Audit\Contracts\AuditableEntityInterface;
use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\GeneralLedger\Models\JournalEntry;

/**
 * Threaded internal note / clarification anchored to a specific journal entry (Prima Nota).
 *
 * @property string $id
 * @property string $journal_entry_id
 * @property string|null $parent_note_id
 * @property string|null $author_user_id
 * @property string|null $author_name
 * @property string|null $author_role
 * @property string $body
 * @property string $status
 * @property Carbon|null $resolved_at
 * @property string|null $resolved_by
 * @property string|null $resolution_comment
 * @property Carbon|null $created_at
 */
final class FiduciaryEntryNote extends BaseEnterpriseModel implements AuditableEntityInterface
{
    use BelongsToCompany;
    use HasImmutableAuditTrail;

    protected $table = 'fiduciary_entry_notes';

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parentNote(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_note_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_note_id')->orderBy('created_at');
    }
}
