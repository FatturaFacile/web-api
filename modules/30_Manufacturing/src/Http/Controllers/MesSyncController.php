<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Manufacturing\Http\Requests\MesSyncIndexRequest;
use Modules\Manufacturing\Http\Requests\MesSyncManifestRequest;
use Modules\Manufacturing\Http\Resources\MesSyncPageResource;
use Modules\Manufacturing\Services\MesSync\MesSyncQuery;

/**
 * Reference data the MES Core pulls: machines, work orders (operations of released orders), shift calendars and reason
 * codes. Authenticated by the signature middleware, scoped to the tenant of the signing key.
 */
final class MesSyncController extends Controller
{
    public function __construct(private readonly MesSyncQuery $query) {}

    /**
     * A page of changes of a resource after the cursor, with tombstones for removed entities.
     */
    public function index(MesSyncIndexRequest $request, string $resource): MesSyncPageResource
    {
        return new MesSyncPageResource($this->query->page(
            $this->tenantId($request),
            $resource,
            $request->validated('cursor'),
            $request->validated('updated_since'),
            $request->pageLimit(),
        ));
    }

    /**
     * A page of the `(entity_id, revision)` pairs of a resource, to detect and repair drift of a replica.
     */
    public function manifest(MesSyncManifestRequest $request, string $resource): MesSyncPageResource
    {
        return new MesSyncPageResource($this->query->manifest(
            $this->tenantId($request),
            $resource,
            $request->validated('cursor'),
            $request->pageLimit(),
        ));
    }

    private function tenantId(Request $request): string
    {
        return (string) $request->attributes->get('mes_tenant_id');
    }
}
