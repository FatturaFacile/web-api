<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\Models\ScimProvisioningLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One SCIM operation of the provisioning log.
 *
 * @mixin ScimProvisioningLog
 */
final class ScimProvisioningLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ScimProvisioningLog $log */
        $log = $this->resource;

        return [
            'id' => $log->id,
            'resource' => $log->resource,
            'operation' => $log->operation,
            'status' => $log->status,
            'resource_id' => $log->resource_id,
            'resource_name' => $log->resource_name,
            'error' => $log->error,
            'occurred_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
