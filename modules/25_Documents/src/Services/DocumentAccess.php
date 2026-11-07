<?php

declare(strict_types=1);

namespace Modules\Documents\Services;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsFolder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Who sees which document. An empty role list on a document or folder means every member; a restricted folder also
 * restricts everything below it. Owners and administrators see everything. What a role cannot see answers 404.
 */
final readonly class DocumentAccess
{
    /**
     * Roles that manage legal holds, retention policies and the AI settings.
     *
     * @var list<string>
     */
    public const array ManagerRoles = ['owner', 'admin'];

    public function __construct(private TenantContext $tenantContext) {}

    public function roleOf(User $user): string
    {
        return (string) $user->getRoleInTenant((string) $this->tenantContext->getTenantId());
    }

    public function isManager(User $user): bool
    {
        return in_array($this->roleOf($user), self::ManagerRoles, true);
    }

    /**
     * @throws AccessDeniedHttpException
     */
    public function assertManager(User $user): void
    {
        if (! $this->isManager($user)) {
            throw new AccessDeniedHttpException('Only owners and administrators can do this.');
        }
    }

    /**
     * @param  Builder<DmsDocument>  $query
     * @return Builder<DmsDocument>
     */
    public function restrict(Builder $query, User $user): Builder
    {
        if ($this->isManager($user)) {
            return $query;
        }

        $role = $this->roleOf($user);
        $deniedFolderIds = $this->deniedFolderIds($role);

        return $query
            ->where(function (Builder $visible) use ($role): void {
                $visible->whereRaw("dms_documents.access_roles = '[]'::jsonb")
                    ->orWhereRaw('dms_documents.access_roles @> ?::jsonb', [json_encode([$role])]);
            })
            ->where(function (Builder $visible) use ($deniedFolderIds): void {
                $visible->whereNull('dms_documents.folder_id')->orWhereNotIn('dms_documents.folder_id', $deniedFolderIds);
            });
    }

    /**
     * Folders the role cannot enter, including everything nested under them.
     *
     * @return list<string>
     */
    public function deniedFolderIds(string $role): array
    {
        $folders = DmsFolder::query()->get(['id', 'parent_id', 'access_roles']);
        $denied = $folders
            ->filter(static fn (DmsFolder $folder): bool => ($folder->access_roles ?? []) !== [] && ! in_array($role, $folder->access_roles, true))
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        do {
            $children = $folders
                ->filter(static fn (DmsFolder $folder): bool => in_array((string) $folder->parent_id, $denied, true) && ! in_array((string) $folder->id, $denied, true))
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            $denied = [...$denied, ...$children];
        } while ($children !== []);

        return array_values($denied);
    }

    public function findVisible(string $id, User $user): DmsDocument
    {
        return $this->restrict(DmsDocument::query(), $user)->findOrFail($id);
    }
}
