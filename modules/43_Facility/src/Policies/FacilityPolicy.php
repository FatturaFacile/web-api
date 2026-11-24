<?php

declare(strict_types=1);

namespace Modules\Facility\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;
use Modules\Facility\Models\FacilityBooking;
use Modules\Facility\Models\FacilityBookingSeries;
use Modules\Facility\Models\FacilityInspection;
use Modules\Facility\Models\FacilityInspectionTemplate;
use Modules\Facility\Models\FacilityMeter;
use Modules\Facility\Models\FacilityMeterReading;
use Modules\Facility\Models\FacilityRequestComment;
use Modules\Facility\Models\FacilityServiceRequest;

/**
 * Every member sees the buildings and spaces, books rooms and desks for themselves and raises service requests. Facility
 * managers (owner, admin, facility_manager or the `facility.manage` permission) maintain the building register, the
 * booking rules and the inspection templates, and book for others. Operators (managers plus technicians and service
 * managers, or `facility.operate`) work the requests, perform inspections and read the meters.
 */
final class FacilityPolicy
{
    use ResolvesTenantMembership;

    /** @var list<string> */
    private const array ManagerRoles = ['owner', 'admin', 'facility_manager'];

    /** @var list<string> */
    private const array OperatorRoles = ['owner', 'admin', 'facility_manager', 'technician', 'service_manager'];

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Model $record): bool
    {
        if (! $this->isMember($user) || ! $this->isOwnRecord($record)) {
            return false;
        }

        if ($record instanceof FacilityServiceRequest) {
            return $this->isOperator($user) || (string) $record->requester_id === (string) $user->id;
        }

        if ($record instanceof FacilityRequestComment || $record instanceof FacilityBooking || $record instanceof FacilityBookingSeries) {
            return true;
        }

        if ($record instanceof FacilityInspection || $record instanceof FacilityInspectionTemplate || $record instanceof FacilityMeter || $record instanceof FacilityMeterReading) {
            return $this->isOperator($user);
        }

        return true;
    }

    /**
     * Any member books for themselves and raises service requests.
     */
    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    /**
     * Maintain the register, the rules, the templates and the meters, or act on behalf of others.
     */
    public function manage(User $user, Model|string|null $record = null): bool
    {
        return $this->hasRoleOrPermission($user, self::ManagerRoles, ['facility.manage', 'facility.*'])
            && ($record === null || is_string($record) || $this->isOwnRecord($record));
    }

    /**
     * Work the service requests, perform inspections, read and record meters.
     */
    public function operate(User $user, Model|string|null $record = null): bool
    {
        return $this->isOperator($user) && ($record === null || is_string($record) || $this->isOwnRecord($record));
    }

    /**
     * Cancel a booking: its owner, or a manager.
     */
    public function cancelBooking(User $user, FacilityBooking $booking): bool
    {
        return $this->isOwnRecord($booking) && ((string) $booking->user_id === (string) $user->id || $this->manage($user));
    }

    /**
     * Check a booking in: the person it was made for, or a manager.
     */
    public function checkIn(User $user, FacilityBooking $booking): bool
    {
        return $this->cancelBooking($user, $booking);
    }

    /**
     * The person who raised a request may cancel it while it is new and reopen it once resolved.
     */
    public function requester(User $user, FacilityServiceRequest $request): bool
    {
        return $this->isOwnRecord($request) && (string) $request->requester_id === (string) $user->id;
    }

    public function isOperator(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::OperatorRoles, ['facility.operate', 'facility.manage', 'facility.*']);
    }
}
