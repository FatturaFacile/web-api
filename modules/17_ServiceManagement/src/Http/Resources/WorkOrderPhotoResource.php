<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\WorkOrderPhoto;
use OpenApi\Attributes as OA;

/**
 * @mixin WorkOrderPhoto
 */
#[OA\Schema(
    schema: 'FsmWorkOrderPhoto',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'work_order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'path', type: 'string'),
        new OA\Property(property: 'stage', type: 'string', enum: ['before', 'during', 'after'], nullable: true),
        new OA\Property(property: 'caption', type: 'string', nullable: true),
        new OA\Property(property: 'captured_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'url', type: 'string', description: 'Authenticated download URL of the image'),
    ]
)]
final class WorkOrderPhotoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'work_order_id' => (string) $this->work_order_id,
            'path' => $this->path,
            'stage' => $this->stage,
            'caption' => $this->caption,
            'captured_at' => $this->captured_at?->toIso8601String(),
            'url' => route('api.v1.fsm.work-orders.photos.show', ['workOrder' => $this->work_order_id, 'photo' => $this->id]),
        ];
    }
}
