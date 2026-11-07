<?php

declare(strict_types=1);

namespace Modules\Workshop\Models;

use App\Models\Contact;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A work folder: one vehicle in the workshop, from reception to delivery and invoice.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $status
 * @property string $folder_number
 * @property string $contact_id
 * @property string $vehicle_id
 * @property string $currency
 * @property ?string $quote_id
 * @property ?string $delivery_note_id
 * @property ?string $invoice_id
 */
final class WorkshopFolder extends WorkshopRecord
{
    use SoftDeletes;

    /** @var list<string> */
    public const array Kinds = ['mechanics', 'bodywork', 'tyres', 'inspection', 'other'];

    /**
     * Allowed manual transitions: from => targets. Invoicing sets the invoiced status itself.
     *
     * @var array<string, list<string>>
     */
    public const array Transitions = [
        'open' => ['in_progress', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => ['delivered', 'in_progress'],
        'delivered' => [],
        'invoiced' => [],
        'cancelled' => [],
    ];

    protected $table = 'workshop_folders';

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'promised_at' => 'datetime', 'mileage_in' => 'integer', 'mileage_out' => 'integer'];
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['invoiced', 'cancelled'], true);
    }

    /**
     * @return HasMany<WorkshopFolderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(WorkshopFolderLine::class, 'folder_id')->orderBy('sort_order');
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
