<?php

declare(strict_types=1);

namespace Modules\Fleet\Services;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Fleet\Models\FleetIncident;
use Modules\Fleet\Models\FleetVehicleAssignment;
use Modules\HumanResources\Models\Employee;

/**
 * Traffic fines, accidents and damages. The driver comes from the assignment of the vehicle on the day of the incident
 * unless it is given, and the payment status moves from unpaid to paid, contested or cancelled.
 */
final readonly class IncidentService
{
    private const array Fields = ['employee_id', 'kind', 'occurred_on', 'reference', 'description', 'location', 'amount', 'currency', 'payment_status', 'due_on', 'paid_on', 'notes'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): FleetIncident
    {
        $data['employee_id'] ??= $this->driverOn((string) $data['vehicle_id'], (string) $data['occurred_on'])?->employee_id;
        $data['payment_status'] ??= FleetIncident::Unpaid;
        $this->assertValid($tenantId, $data);

        return FleetIncident::create([
            ...array_intersect_key($this->normalizePayment($data), array_flip(self::Fields)),
            'tenant_id' => $tenantId,
            'vehicle_id' => $data['vehicle_id'],
        ])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FleetIncident $incident, array $data): FleetIncident
    {
        $merged = [...$incident->only(self::Fields), ...$data];
        $this->assertValid((string) $incident->tenant_id, $merged);
        $incident->update(array_intersect_key($this->normalizePayment($merged), array_flip(self::Fields)));

        return $incident->refresh();
    }

    /**
     * Marks the incident as paid on the given date (today by default).
     */
    public function pay(FleetIncident $incident, ?string $paidOn): FleetIncident
    {
        return $this->update($incident, ['payment_status' => FleetIncident::Paid, 'paid_on' => $paidOn ?? Carbon::today()->toDateString()]);
    }

    /**
     * Whether an unpaid incident is past its due date.
     */
    public static function isOverdue(FleetIncident $incident): bool
    {
        return $incident->payment_status === FleetIncident::Unpaid && $incident->due_on !== null && $incident->due_on->copy()->startOfDay()->lt(Carbon::today());
    }

    private function driverOn(string $vehicleId, string $date): ?FleetVehicleAssignment
    {
        $day = Carbon::parse($date)->startOfDay();

        return FleetVehicleAssignment::query()
            ->where('vehicle_id', $vehicleId)
            ->get()
            ->first(static fn (FleetVehicleAssignment $assignment): bool => $assignment->starts_on->lte($day) && ($assignment->ends_on === null || $assignment->ends_on->gt($day) || $assignment->ends_on->equalTo($assignment->starts_on)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertValid(string $tenantId, array $data): void
    {
        if (($data['employee_id'] ?? null) !== null && ! Employee::query()->whereKey($data['employee_id'])->where('tenant_id', $tenantId)->exists()) {
            throw ValidationException::withMessages(['employee_id' => [__('fleet_errors.assignment.employee_missing')]]);
        }
    }

    /**
     * A paid incident carries its payment date (today when missing); any other status has none.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePayment(array $data): array
    {
        if ($data['payment_status'] === FleetIncident::Paid) {
            $data['paid_on'] = ($data['paid_on'] ?? null) ?? Carbon::today()->toDateString();
        } else {
            $data['paid_on'] = null;
        }

        return $data;
    }
}
