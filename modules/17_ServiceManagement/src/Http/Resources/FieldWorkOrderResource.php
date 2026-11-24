<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\WorkOrder;
use OpenApi\Attributes as OA;

/**
 * @mixin WorkOrder
 */
#[OA\Schema(
    schema: 'FsmFieldWorkOrder',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'work_order_number', type: 'string'),
        new OA\Property(property: 'work_order_type', type: 'string', enum: ['preventive', 'corrective', 'installation', 'warranty']),
        new OA\Property(property: 'status', type: 'string', enum: ['unscheduled', 'dispatched', 'en_route', 'on_site', 'completed', 'invoiced', 'cancelled']),
        new OA\Property(property: 'priority', type: 'string', enum: ['low', 'normal', 'high', 'urgent']),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'problem_description', type: 'string', nullable: true),
        new OA\Property(property: 'customer_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'equipment_passport_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'service_contract_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'assigned_technician_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'required_skills', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'required_parts', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'scheduled_start', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'scheduled_end', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'actual_arrival', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'actual_completion', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'site', type: 'object'),
        new OA\Property(property: 'client_uuid', type: 'string', nullable: true),
        new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true),
    ],
)]
final class FieldWorkOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'work_order_number' => (string) $this->work_order_number,
            'work_order_type' => (string) $this->work_type,
            'status' => (string) $this->status,
            'priority' => (string) $this->priority,
            'title' => (string) $this->title,
            'problem_description' => $this->description,
            'customer_id' => $this->customer_id === null ? null : (string) $this->customer_id,
            'equipment_passport_id' => $this->equipment_passport_id === null ? null : (string) $this->equipment_passport_id,
            'service_contract_id' => $this->service_contract_id === null ? null : (string) $this->service_contract_id,
            'assigned_technician_id' => $this->assigned_technician_id === null ? null : (string) $this->assigned_technician_id,
            'required_skills' => array_values((array) $this->required_skills),
            'required_parts' => array_values((array) $this->required_parts),
            'scheduled_start' => $this->scheduled_start?->toIso8601String(),
            'scheduled_end' => $this->scheduled_end?->toIso8601String(),
            'actual_arrival' => $this->actual_arrival?->toIso8601String(),
            'actual_completion' => $this->actual_end?->toIso8601String(),
            'site' => (array) $this->location,
            'client_uuid' => $this->client_uuid,
            'invoice_id' => $this->invoice_id === null ? null : (string) $this->invoice_id,
        ];
    }
}
