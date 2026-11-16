<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\BusinessIntelligence\Models\BiDashboard;
use Modules\BusinessIntelligence\Models\BiSavedQuery;
use Modules\BusinessIntelligence\Models\BiShare;

/**
 * Sharing of dashboards and saved queries with members of the company, for viewing or editing. A resource is private
 * until it is shared with the whole company or with named members; the `shared` visibility is derived (private with at
 * least one share), so removing the last share makes it private again.
 */
final readonly class SharingService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * The model a share points to.
     */
    public function resource(string $type, string $id): BiDashboard|BiSavedQuery
    {
        return match ($type) {
            BiShare::Dashboard => BiDashboard::query()->findOrFail($id),
            default => BiSavedQuery::query()->findOrFail($id),
        };
    }

    /**
     * The resource type of a model.
     */
    public function typeOf(Model $resource): string
    {
        return $resource instanceof BiDashboard ? BiShare::Dashboard : BiShare::SavedQuery;
    }

    /**
     * Shares the resource with a member, or changes the permission of an existing share.
     *
     * @throws ValidationException
     */
    public function share(BiDashboard|BiSavedQuery $resource, User $by, string $userId, string $permission): BiShare
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $grantee = User::query()->find($userId);

        if ($grantee === null || $grantee->getRoleInTenant($tenantId) === null) {
            throw ValidationException::withMessages(['user_id' => [__('bi_errors.share.not_member')]]);
        }

        if ((string) $resource->owner_id === $userId) {
            throw ValidationException::withMessages(['user_id' => [__('bi_errors.share.owner')]]);
        }

        $type = $this->typeOf($resource);
        $share = BiShare::query()->where('resource_type', $type)->where('resource_id', $resource->id)->where('user_id', $userId)->first();

        if ($share === null) {
            $share = BiShare::create(['tenant_id' => $tenantId, 'resource_type' => $type, 'resource_id' => $resource->id, 'user_id' => $userId, 'permission' => $permission, 'created_by' => $by->id]);
        } else {
            $share->update(['permission' => $permission]);
        }

        $this->syncVisibility($resource);

        return $share->refresh();
    }

    /**
     * Withdraws a share.
     */
    public function unshare(BiShare $share): void
    {
        $resource = $this->resource($share->resource_type, $share->resource_id);
        $share->delete();
        $this->syncVisibility($resource);
    }

    /**
     * Private with shares is `shared`, `shared` without shares is private; `company` stays as it is.
     */
    public function syncVisibility(BiDashboard|BiSavedQuery $resource): void
    {
        $resource->refresh();

        if ($resource->visibility === BiSavedQuery::Company) {
            return;
        }

        $hasShares = BiShare::query()->where('resource_type', $this->typeOf($resource))->where('resource_id', $resource->id)->exists();
        $visibility = $hasShares ? BiSavedQuery::Shared : BiSavedQuery::Private;

        if ($resource->visibility !== $visibility) {
            $resource->update(['visibility' => $visibility]);
        }
    }

    /**
     * Removes the shares of a resource that is deleted.
     */
    public function purge(BiDashboard|BiSavedQuery $resource): void
    {
        BiShare::query()->where('resource_type', $this->typeOf($resource))->where('resource_id', $resource->id)->delete();
    }
}
