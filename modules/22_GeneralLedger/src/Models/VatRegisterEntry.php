<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Protocol number of a document in a VAT register.
 */
final class VatRegisterEntry extends BaseEnterpriseModel
{
    protected $table = 'vat_register_entries';

    protected function casts(): array
    {
        return [
            'protocol_number' => 'integer',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(VatRegister::class, 'vat_register_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
