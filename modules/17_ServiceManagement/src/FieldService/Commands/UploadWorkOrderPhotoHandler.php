<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\ServiceManagement\Models\WorkOrderPhoto;

/**
 * Stores a technician photo of a work order on the tenant-scoped private disk. Idempotent on `client_uuid` per tenant:
 * a replay returns the existing record (its `wasRecentlyCreated` flag is false) without storing the file again.
 */
final class UploadWorkOrderPhotoHandler
{
    public function __invoke(WorkOrder $workOrder, UploadedFile $file, string $clientUuid, ?string $stage, ?string $caption, ?CarbonImmutable $capturedAt, ?string $actorUserId): WorkOrderPhoto
    {
        $existing = $this->find($workOrder, $clientUuid);

        if ($existing !== null) {
            return $existing;
        }

        $path = Storage::disk('local')->putFile("field-service/photos/{$workOrder->tenant_id}/{$workOrder->id}", $file);

        try {
            return WorkOrderPhoto::create([
                'tenant_id' => $workOrder->tenant_id,
                'work_order_id' => $workOrder->id,
                'client_uuid' => $clientUuid,
                'path' => (string) $path,
                'stage' => $stage,
                'caption' => $caption,
                'captured_at' => $capturedAt,
                'uploaded_by' => $actorUserId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent replay won the race: drop our copy of the file and return the winner.
            Storage::disk('local')->delete((string) $path);

            return $this->find($workOrder, $clientUuid) ?? throw ValidationException::withMessages(['client_uuid' => 'duplicate']);
        }
    }

    private function find(WorkOrder $workOrder, string $clientUuid): ?WorkOrderPhoto
    {
        $photo = WorkOrderPhoto::query()->where('tenant_id', $workOrder->tenant_id)->where('client_uuid', $clientUuid)->first();

        if ($photo !== null && (string) $photo->work_order_id !== (string) $workOrder->id) {
            throw ValidationException::withMessages(['client_uuid' => __('field_service.errors.photos_not_in_work_order')]);
        }

        return $photo;
    }
}
