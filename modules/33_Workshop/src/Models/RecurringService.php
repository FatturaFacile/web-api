<?php

declare(strict_types=1);

namespace Modules\Workshop\Models;

use App\Models\Subscription;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service billed again at every period (yearly tyre storage, a maintenance plan). The billing itself is a subscription
 * of the sales module, run by the subscriptions:process command through ConvertToInvoice.
 */
final class RecurringService extends WorkshopRecord
{
    /** @var list<string> */
    public const array Types = ['tyre_storage', 'maintenance_plan', 'other'];

    protected $table = 'workshop_recurring_services';

    /** @var list<string> */
    protected $appends = ['license_plate', 'name', 'interval', 'start_date', 'next_due_date', 'currency', 'unit_price', 'is_active'];

    /**
     * Calculated grid columns (portable SQL, see the GridColumns class of the grid engine). They mirror the appended
     * attributes below, so filter, sort, group and the value lists run over the whole dataset.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'license_plate' => '(SELECT vehicles.license_plate FROM vehicles WHERE vehicles.id = workshop_recurring_services.vehicle_id)',
            'name' => '(SELECT subscriptions.name FROM subscriptions WHERE subscriptions.id = workshop_recurring_services.subscription_id)',
            'interval' => '(SELECT subscriptions.interval FROM subscriptions WHERE subscriptions.id = workshop_recurring_services.subscription_id)',
            'start_date' => '(SELECT SUBSTR(CAST(subscriptions.start_date AS TEXT), 1, 10) FROM subscriptions WHERE subscriptions.id = workshop_recurring_services.subscription_id)',
            'next_due_date' => '(SELECT SUBSTR(CAST(subscriptions.next_billing_date AS TEXT), 1, 10) FROM subscriptions WHERE subscriptions.id = workshop_recurring_services.subscription_id)',
            'currency' => '(SELECT subscriptions.currency FROM subscriptions WHERE subscriptions.id = workshop_recurring_services.subscription_id)',
            'unit_price' => '(SELECT SUM(subscription_items.unit_price * subscription_items.quantity) FROM subscription_items WHERE subscription_items.subscription_id = workshop_recurring_services.subscription_id)',
            'is_active' => "(SELECT CASE WHEN subscriptions.status IN ('cancelled', 'expired') THEN 0 ELSE 1 END FROM subscriptions WHERE subscriptions.id = workshop_recurring_services.subscription_id)",
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
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
    protected function name(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->subscription?->name);
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function interval(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->subscription?->interval?->value);
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function startDate(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->subscription?->start_date?->toDateString());
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function nextDueDate(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->subscription?->next_billing_date?->toDateString());
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function currency(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->subscription?->currency);
    }

    /**
     * Sum of the quantity x unit price of the subscription items (a recurring service has a single item).
     *
     * @return Attribute<string|null, never>
     */
    protected function unitPrice(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->subscription === null) {
                return null;
            }

            $total = '0';

            foreach ($this->subscription->items as $item) {
                $total = bcadd($total, bcmul((string) $item->quantity, (string) $item->unit_price, 4), 4);
            }

            return bcadd($total, '0', 2);
        });
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isActive(): Attribute
    {
        return Attribute::get(fn (): bool => $this->subscription !== null && ! in_array($this->subscription->status, ['cancelled', 'expired'], true));
    }
}
