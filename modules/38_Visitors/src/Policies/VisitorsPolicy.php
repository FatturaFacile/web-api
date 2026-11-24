<?php

declare(strict_types=1);

namespace Modules\Visitors\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;
use Modules\Visitors\Models\Visitor;
use Modules\Visitors\Models\VisitorVisit;

/**
 * Any member announces and follows the visits of their own guests. Reception (owner, admin, receptionist or the
 * `visitors.manage` permission) sees every visitor, checks people in and out and reads who is on site. Kiosks are
 * registered by the owner and the administrators only.
 */
final class VisitorsPolicy
{
    use ResolvesTenantMembership;

    /** @var list<string> */
    public const array ReceptionRoles = ['owner', 'admin', 'receptionist'];

    /** @var list<string> */
    private const array KioskRoles = ['owner', 'admin'];

    /**
     * View any.
     */
    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    /**
     * View.
     */
    public function view(User $user, Model $record): bool
    {
        if (! $this->isMember($user) || ! $this->isOwnRecord($record)) {
            return false;
        }

        return $this->isReception($user) || $this->hosts($user, $record);
    }

    /**
     * Create.
     */
    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    /**
     * Update a record.
     */
    public function update(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->isReception($user);
    }

    /**
     * Delete.
     */
    public function delete(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->isReception($user);
    }

    /**
     * Badges, check-in and check-out are handled at reception.
     */
    public function receive(User $user, ?Model $record = null): bool
    {
        return $this->isReception($user) && ($record === null || $this->isOwnRecord($record));
    }

    /**
     * Reception or the host may call off a visit that has not started.
     */
    public function cancel(User $user, VisitorVisit $visit): bool
    {
        return $this->isOwnRecord($visit) && ($this->isReception($user) || $this->hosts($user, $visit));
    }

    /**
     * Manage kiosks.
     */
    public function manageKiosks(User $user, ?Model $record = null): bool
    {
        return $this->hasRoleOrPermission($user, self::KioskRoles, ['visitors.kiosks']) && ($record === null || $this->isOwnRecord($record));
    }

    /**
     * Is reception.
     */
    public function isReception(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::ReceptionRoles, ['visitors.manage']);
    }

    private function hosts(User $user, Model $record): bool
    {
        if ($record instanceof VisitorVisit) {
            return (string) $record->host_user_id === (string) $user->id;
        }

        if ($record instanceof Visitor) {
            return $record->visits()->where('host_user_id', $user->id)->exists();
        }

        return false;
    }
}
