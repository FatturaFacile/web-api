<?php

declare(strict_types=1);

namespace Modules\Collaboration\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Collaboration\Http\Controllers\Concerns\InteractsWithCollaboration;
use Modules\Collaboration\Http\Resources\ActivityResource;
use OpenApi\Attributes as OA;

/**
 * The timeline of a record, read from the audit ledger.
 */
final class ActivityController extends Controller
{
    use InteractsWithCollaboration;

    #[OA\Get(path: '/api/v1/collaboration/records/{type}/{recordId}/activity', operationId: 'listCollaborationActivity', description: 'What happened to a record (created, updated, deleted), newest first, read from the audit ledger. Only the names of the changed fields are returned.', summary: 'Activity timeline of a record', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Collaboration'], parameters: [new OA\Parameter(ref: '#/components/parameters/CollabRecordType'), new OA\Parameter(ref: '#/components/parameters/CollabRecordId')], responses: [
        new OA\Response(response: 200, description: 'Activity timeline of a record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CollabActivity'))])),
        new OA\Response(ref: '#/components/responses/CollabUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CollabForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CollabNotFound', response: 404),
    ])]
    public function index(string $type, string $recordId): AnonymousResourceCollection
    {
        $recordType = $this->recordType($type);
        $record = $this->record($recordType, $recordId);

        return ActivityResource::collection(
            AuditLedgerEntry::query()
                ->where('tenant_id', $this->tenantId())
                ->where('resource_type', substr($recordType->modelClass, 0, 60))
                ->where('resource_id', (string) $record->getKey())
                ->with('user:id,name')
                ->orderByDesc('event_timestamp')
                ->orderByDesc('id')
                ->paginate($this->perPage()),
        );
    }
}
