<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Models;

use App\Models\Contact;
use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The cash-discount (Skonto / escompte) agreement with a supplier: `skonto_percentage` off the invoice when it is paid
 * within `skonto_days` of the invoice date, `net_days` for the full amount.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $supplier_id
 * @property int|null $net_days
 * @property string $skonto_percentage
 * @property int $skonto_days
 * @property string|null $clause
 * @property string|null $reference
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property bool $is_active
 * @property string|null $notes
 */
final class ApSupplierTerm extends BaseEnterpriseModel
{
    protected $table = 'ap_supplier_terms';

    protected function casts(): array
    {
        return [
            'net_days' => 'integer',
            'skonto_percentage' => 'decimal:2',
            'skonto_days' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    /**
     * Whether the agreement applies to a document of that date.
     */
    public function appliesOn(CarbonInterface $date): bool
    {
        return $this->is_active
            && ($this->valid_from === null || $this->valid_from->lte($date))
            && ($this->valid_to === null || $this->valid_to->gte($date));
    }
}
