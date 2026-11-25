<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A manual, dispute or legal-hold exclusion of a customer or one open item from dunning.
 *
 * @property string $customer_id
 * @property string|null $receivable_item_id
 * @property string $type
 * @property string $reason
 * @property string|null $notes
 * @property string|null $created_by
 * @property Carbon|null $released_at
 * @property string|null $released_by
 * @property string|null $release_note
 */
final class Exclusion extends BaseEnterpriseModel
{
    protected $table = 'dc_exclusions';

    protected function casts(): array
    {
        return ['released_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }
}
