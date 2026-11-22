<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GeneralLedger\Models\AccountingTemplate;

/**
 * @mixin AccountingTemplate
 */
final class AccountingTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'category' => $this->category,
            'journal_type' => $this->journal_type,
            'ledger_type' => $this->ledger_type,
            'name' => $this->localizedName($locale),
            'names' => $this->names,
            'description' => $this->descriptions[$locale] ?? $this->descriptions['en'] ?? null,
            'descriptions' => $this->descriptions,
            'parameters' => $this->parameters,
            'lines' => $this->lines,
            'is_system' => $this->is_system,
            'is_active' => $this->is_active,
        ];
    }
}
