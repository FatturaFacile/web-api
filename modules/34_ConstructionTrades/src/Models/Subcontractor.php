<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Models;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subcontractor of the company (a firm or a craftsman that works on its sites). It may be suspended, for instance when
 * its compliance documents have expired, and then takes no new subcontract.
 *
 * @property string $id
 * @property string $name
 * @property string $status
 */
final class Subcontractor extends TradesRecord
{
    /** @var list<string> */
    public const array Statuses = ['active', 'suspended'];

    protected $table = 'ct_subcontractors';

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
