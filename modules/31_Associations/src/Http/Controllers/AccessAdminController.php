<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Associations\Http\Requests\RegisterDeviceRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\AccessArea;
use Modules\Associations\Models\AccessDevice;
use Modules\Associations\Models\AccessLog;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\Services\AccessDeviceService;
use Modules\Associations\Services\AccessVerifier;
use Modules\Associations\Support\AssociationCapability;

/**
 * The staff side of access control: readers, access log and occupancy.
 */
final class AccessAdminController extends AssociationController
{
    public function __construct(
        private readonly AccessDeviceService $devices,
        private readonly AccessVerifier $verifier,
    ) {}

    #[AssociationGet('/api/v1/associations/access-devices', 'listAssociationAccessDevices', 'List the readers')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Manage);

        return AssociationRecordResource::collection(AccessDevice::query()->gridPaginate($this->perPage($request)));
    }

    #[AssociationPost('/api/v1/associations/access-devices', 'registerAssociationAccessDevice', 'Register a reader. Its secret is shown only in this response', 'AssociationDeviceRequest')]
    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $result = $this->devices->register($request->validated());

        return response()->json(['data' => [...$result['device']->toArray(), 'secret' => $result['secret']]], 201);
    }

    #[AssociationPost('/api/v1/associations/access-devices/{id}/rotate-secret', 'rotateAssociationAccessDeviceSecret', 'Replace the secret of a reader')]
    public function rotate(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);

        return response()->json(['data' => ['secret' => $this->devices->rotateSecret(AccessDevice::query()->findOrFail($id))]]);
    }

    #[AssociationPost('/api/v1/associations/access-devices/{id}/deactivate', 'deactivateAssociationAccessDevice', 'Disable a reader')]
    public function deactivate(string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);
        $device = AccessDevice::query()->findOrFail($id);
        $device->update(['is_active' => false]);

        return new AssociationRecordResource($device);
    }

    #[AssociationGet('/api/v1/associations/access-logs', 'listAssociationAccessLogs', 'List the access log', query: ['area_id', 'member_id', 'decision'])]
    public function logs(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Manage);

        $logs = AccessLog::query()
            ->when($request->filled('area_id'), static fn ($query) => $query->where('area_id', (string) $request->query('area_id')))
            ->when($request->filled('member_id'), static fn ($query) => $query->where('member_id', (string) $request->query('member_id')))
            ->when($request->filled('decision'), static fn ($query) => $query->where('decision', (string) $request->query('decision')))
            ->orderByDesc('occurred_at')
            ->gridPaginate($this->perPage($request));

        return AssociationRecordResource::collection($logs);
    }

    #[AssociationGet('/api/v1/associations/access-areas/{id}/occupancy', 'showAssociationAreaOccupancy', 'Show how many people are in an area')]
    public function occupancy(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Read);
        $area = AccessArea::query()->findOrFail($id);

        return response()->json(['data' => ['occupancy' => $this->verifier->occupancy($area), 'capacity' => $area->capacity]]);
    }
}
