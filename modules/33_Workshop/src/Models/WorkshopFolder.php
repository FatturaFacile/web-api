<?php

declare(strict_types=1);

namespace Modules\Workshop\Models;

use App\Models\Contact;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /** @var list<string> */
    protected $appends = ['license_plate', 'vin', 'vehicle_model', 'customer_name'];

    /**
     * Calculated grid columns (portable SQL, see the GridColumns class of the grid engine). They mirror the appended
     * attributes below, so filter, sort, group and the value lists run over the whole dataset.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'license_plate' => '(SELECT vehicles.license_plate FROM vehicles WHERE vehicles.id = workshop_folders.vehicle_id)',
            'vin' => '(SELECT vehicles.vin_chassis FROM vehicles WHERE vehicles.id = workshop_folders.vehicle_id)',
            'vehicle_model' => "(SELECT TRIM(COALESCE(vehicles.make, '') || ' ' || COALESCE(vehicles.model, '')) FROM vehicles WHERE vehicles.id = workshop_folders.vehicle_id)",
            'customer_name' => "(SELECT COALESCE(NULLIF(contacts.company_name, ''), NULLIF(TRIM(COALESCE(contacts.first_name, '') || ' ' || COALESCE(contacts.last_name, '')), ''), '-') FROM contacts WHERE contacts.id = workshop_folders.contact_id)",
        ];
    }

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'promised_at' => 'datetime', 'mileage_in' => 'integer', 'mileage_out' => 'integer'];
    }

    /**
     * Whether the folder is final (delivered, invoiced or cancelled) and its lines and data can no longer change.
     */
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

    /**
     * @return Attribute<string|null, never>
     */
    protected function licensePlate(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->vehicle?->license_plate);
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function vin(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->vehicle?->vin_chassis);
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function vehicleModel(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->vehicle === null ? null : trim(($this->vehicle->make ?? '').' '.($this->vehicle->model ?? '')));
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function customerName(): Attribute
    {
        return Attribute::get(function (): ?string {
            $contact = $this->contact;

            if ($contact === null) {
                return null;
            }

            $person = trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));

            return filled($contact->company_name) ? $contact->company_name : ($person !== '' ? $person : '-');
        });
    }
}
