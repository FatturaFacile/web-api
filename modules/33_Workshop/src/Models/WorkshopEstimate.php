<?php

declare(strict_types=1);

namespace Modules\Workshop\Models;

use App\Models\Contact;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A workshop estimate: labour and part lines offered to the customer before any work starts. It is sent, approved or
 * rejected by the customer, and an approved one is converted into a work folder (the work order of the workshop).
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $estimate_number
 * @property string $vehicle_id
 * @property string $contact_id
 * @property string $kind
 * @property string $status
 * @property string $currency
 * @property ?string $folder_id
 * @property Carbon|null $valid_until
 */
final class WorkshopEstimate extends WorkshopRecord
{
    /**
     * Allowed transitions: from => targets. Conversion to a folder sets the converted status itself.
     *
     * @var array<string, list<string>>
     */
    public const array Transitions = [
        'draft' => ['sent'],
        'sent' => ['approved', 'rejected'],
        'approved' => ['converted'],
        'rejected' => [],
        'converted' => [],
    ];

    protected $table = 'workshop_estimates';

    protected function casts(): array
    {
        return [
            'valid_until' => 'date:Y-m-d',
            'mileage_in' => 'integer',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'sent_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WorkshopEstimateLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(WorkshopEstimateLine::class, 'estimate_id')->orderBy('sort_order');
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
