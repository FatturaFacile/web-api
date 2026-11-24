<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ResourcePlanning\Infrastructure\Models\Skill;

/**
 * @mixin Skill
 */
final class SkillResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'skill_name' => $this->skill_name,
            'requires_certification' => $this->requires_certification,
            'certification_code' => $this->certification_code,
        ];
    }
}
