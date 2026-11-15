<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;
use Modules\BusinessIntelligence\Models\BiDashboard;
use Modules\BusinessIntelligence\Models\BiSavedQuery;
use Modules\BusinessIntelligence\Models\BiShare;
use Modules\BusinessIntelligence\Models\BiSnapshot;

/**
 * Who may use the business intelligence records. Dashboards and saved queries are private to their owner until shared
 * with the company or with named members (`view` or `edit`); only the owner, or an owner or administrator of the company,
 * deletes them, shares them or removes shares. Calculated measures belong to the company: members of the module read
 * them, owners and administrators of the company or the member who defined one change it. Everything stays inside the
 * active company. What a viewer finds in a dashboard is further limited by the module of each dataset, which the report
 * engine checks.
 */
final class BiPolicy
{
    use ResolvesTenantMembership;

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Model $record): bool
    {
        if (! $this->isMember($user) || ! $this->isOwnRecord($record)) {
            return false;
        }

        if (! $record instanceof BiDashboard && ! $record instanceof BiSavedQuery) {
            return true;
        }

        return $this->accessOf($user, $record) !== null;
    }

    public function update(User $user, Model $record): bool
    {
        if (! $this->isMember($user) || ! $this->isOwnRecord($record)) {
            return false;
        }

        if ($record instanceof BiDashboard || $record instanceof BiSavedQuery) {
            return in_array($this->accessOf($user, $record), ['owner', BiShare::Edit], true);
        }

        return $this->isManager($user) || (string) $record->getAttribute('created_by') === (string) $user->id;
    }

    /**
     * Delete a record, and for dashboards and saved queries also share them and remove shares.
     */
    public function delete(User $user, Model $record): bool
    {
        if (! $this->isMember($user) || ! $this->isOwnRecord($record)) {
            return false;
        }

        if ($record instanceof BiDashboard || $record instanceof BiSavedQuery) {
            return $this->accessOf($user, $record) === 'owner' || $this->isManager($user);
        }

        if ($record instanceof BiSnapshot) {
            return $this->isManager($user) || (string) $record->taken_by === (string) $user->id;
        }

        return $this->isManager($user) || (string) $record->getAttribute('created_by') === (string) $user->id;
    }

    /**
     * Share a dashboard or a saved query.
     */
    public function share(User $user, Model $record): bool
    {
        return $this->delete($user, $record);
    }

    /**
     * How the user reaches a dashboard or a saved query: `owner`, `edit`, `view`, or null when it is closed to them.
     */
    private function accessOf(User $user, BiDashboard|BiSavedQuery $record): ?string
    {
        if ((string) $record->owner_id === (string) $user->id) {
            return 'owner';
        }

        $share = BiShare::query()
            ->where('resource_type', $record instanceof BiDashboard ? BiShare::Dashboard : BiShare::SavedQuery)
            ->where('resource_id', $record->id)
            ->where('user_id', $user->id)
            ->first();

        if ($share !== null) {
            return $share->permission;
        }

        return $record->visibility === BiSavedQuery::Company ? BiShare::View : null;
    }

    private function isManager(User $user): bool
    {
        return $this->hasRoleOrPermission($user, ['owner', 'admin']);
    }
}
