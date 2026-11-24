<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TimeRecordingProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'employee_id' => $this->user_id,
            'is_configured' => $this->exists,
            ...$this->auditValues(),
        ];
    }
}
