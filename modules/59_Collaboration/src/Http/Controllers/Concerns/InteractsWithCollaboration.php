<?php

declare(strict_types=1);

namespace Modules\Collaboration\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Modules\Collaboration\Services\CollaborationRegistry;
use Modules\Collaboration\Services\RecordType;

/**
 * Tenant, pagination and record resolution of the request for the collaboration controllers.
 */
trait InteractsWithCollaboration
{
    private function tenantId(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId() ?? request()->user()?->tenant_id;
        abort_if($tenantId === null, 403, 'Missing or unresolvable tenant context');

        return (string) $tenantId;
    }

    private function perPage(): int
    {
        return max(1, min(100, (int) request()->query('per_page', 25)));
    }

    /**
     * The registered record type of the URL, when the member may read its module.
     */
    private function recordType(string $type): RecordType
    {
        $registry = app(CollaborationRegistry::class);
        $recordType = $registry->find($type);
        abort_if($recordType === null, 404, 'Unknown record type');
        abort_unless($registry->mayRead(request()->user(), $this->tenantId(), $recordType), 403, 'Your role does not allow reading this kind of record');

        return $recordType;
    }

    /**
     * The record of the active company that the URL points at.
     */
    private function record(RecordType $type, string $id): Model
    {
        $model = $type->modelClass::query()
            ->where((new $type->modelClass)->qualifyColumn('tenant_id'), $this->tenantId())
            ->whereKey($id)
            ->first();
        abort_if($model === null, 404, 'Record not found in the active company');

        return $model;
    }
}
