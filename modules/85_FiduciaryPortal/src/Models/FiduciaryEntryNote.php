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
use Modules\GeneralLedger\Models\JournalEntry;

/**
 * Threaded internal note / clarification anchored to a specific journal entry (Prima Nota).
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

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function parentNote(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_note_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_note_id')->orderBy('created_at');
    }
}
