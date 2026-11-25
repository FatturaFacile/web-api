<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FranchiseNetwork\Domain\Support\LocalizedText;
use Modules\FranchiseNetwork\Models\AuditTemplate;

/**
 * @mixin AuditTemplate
 */
final class TemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'label' => LocalizedText::pick($this->labels, app()->getLocale()),
            'is_preset' => $this->tenant_id === null,
            'pass_threshold_percent' => $this->pass_threshold_percent,
            'is_active' => $this->is_active,
            'criteria' => array_map(static fn (array $criterion): array => ['code' => $criterion['code'], 'category' => $criterion['category'], 'label' => LocalizedText::pick($criterion['labels'] ?? [], app()->getLocale()), 'hint' => LocalizedText::pick($criterion['hints'] ?? [], app()->getLocale()), 'weight' => $criterion['weight'] ?? 1, 'max_score' => $criterion['max_score'] ?? 5, 'critical' => (bool) ($criterion['critical'] ?? false)], $this->criteria),
        ];
    }
}
