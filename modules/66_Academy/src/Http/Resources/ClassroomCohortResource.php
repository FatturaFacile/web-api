<?php

declare(strict_types=1);

namespace Modules\Academy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Academy\Models\ClassroomCohort;

/**
 * @mixin ClassroomCohort
 */
final class ClassroomCohortResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'join_code' => $this->join_code,
            'teacher_id' => (string) $this->teacher_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
