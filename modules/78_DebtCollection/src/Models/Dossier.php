<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Swiss debt enforcement request (Betreibungsbegehren) dossier.
 *
 * @property string $number
 * @property string $customer_id
 * @property string $currency
 * @property string $principal
 * @property string $interest
 * @property string $fee
 * @property string $total
 * @property string $status
 * @property string|null $office_id
 * @property array<string, mixed>|null $office_snapshot
 * @property list<string> $receivable_item_ids
 * @property array<string, mixed>|null $snapshot
 * @property string $locale
 * @property string|null $pdf_path
 * @property string|null $bundle_path
 * @property Carbon|null $generated_at
 * @property Carbon|null $submitted_at
 * @property string|null $created_by
 */
final class Dossier extends BaseEnterpriseModel
{
    protected $table = 'dc_dossiers';

    protected function casts(): array
    {
        return ['principal' => 'decimal:2', 'interest' => 'decimal:2', 'fee' => 'decimal:2', 'total' => 'decimal:2', 'office_snapshot' => 'array', 'receivable_item_ids' => 'array', 'snapshot' => 'array', 'generated_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }
}
