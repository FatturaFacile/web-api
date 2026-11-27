<?php

declare(strict_types=1);

namespace Modules\Esterometro\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Esterometro\Models\SelfInvoiceClassificationRule;

/**
 * @mixin SelfInvoiceClassificationRule
 */
final class SelfInvoiceRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SelfInvoiceClassificationRule $rule */
        $rule = $this->resource;

        return [
            'id' => $rule->id,
            'tenant_id' => $rule->tenant_id,
            'is_global' => $rule->tenant_id === null,
            'code' => $rule->code,
            'name' => $rule->name,
            'document_type' => $rule->document_type->value,
            'supplier_region' => $rule->supplier_region,
            'supplier_country_code' => $rule->supplier_country_code,
            'transaction_nature' => (string) $rule->transaction_nature,
            'source_vat_nature_prefix' => $rule->source_vat_nature_prefix,
            'default_vat_rate' => (float) $rule->default_vat_rate,
            'default_vat_nature' => $rule->default_vat_nature,
            'default_deductibility_percent' => (float) $rule->default_deductibility_percent,
            'soggetto_emittente' => $rule->soggetto_emittente,
            'priority' => $rule->priority,
            'legal_reference' => $rule->legal_reference,
            'is_active' => $rule->is_active,
        ];
    }
}
