<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use Modules\AiCognitive\Domain\Enums\DataClassification;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;
use Modules\AiCognitive\Infrastructure\Models\AiAutomationRule;

/**
 * Zero-click approval versus the human review queue. A workflow without a rule of the tenant is safe by default: nothing is
 * approved without a person. Only suggestions that create or link an ERP record may be approved automatically; alerts and
 * recommendations (anomalies, insights) always wait for a person.
 */
final class AutomationPolicy
{
    /**
     * @return array{enabled: bool, auto_approve: bool, min_confidence: float, max_amount: float|null, classification: DataClassification, preferred_provider: string|null}
     */
    public function rule(string $tenantId, string $workflow): array
    {
        $rule = AiAutomationRule::query()->where('tenant_id', $tenantId)->where('workflow', $workflow)->first();
        $piiHeavy = str_starts_with($workflow, 'extraction_') || $workflow === 'accounting_coding';

        if ($rule === null) {
            return [
                'enabled' => true,
                'auto_approve' => false,
                'min_confidence' => (float) config('ai_cognitive.automation.default_auto_approve_confidence', 0.95),
                'max_amount' => (float) config('ai_cognitive.automation.default_max_auto_amount', 1000),
                'classification' => $piiHeavy ? DataClassification::ConfidentialPii : DataClassification::Internal,
                'preferred_provider' => null,
            ];
        }

        return [
            'enabled' => $rule->is_enabled,
            'auto_approve' => $rule->auto_approve_enabled,
            'min_confidence' => (float) $rule->auto_approve_min_confidence,
            'max_amount' => $rule->max_auto_amount === null ? null : (float) $rule->max_auto_amount,
            'classification' => $rule->data_classification,
            'preferred_provider' => $rule->preferred_provider,
        ];
    }

    public function allowsAutoApproval(string $tenantId, SuggestionKind $kind, float $confidence, ?float $amount): bool
    {
        if (! $this->isAutomatable($kind)) {
            return false;
        }

        $rule = $this->rule($tenantId, $kind->workflow());

        if (! $rule['enabled'] || ! $rule['auto_approve'] || $confidence < $rule['min_confidence']) {
            return false;
        }

        return $rule['max_amount'] === null || $amount === null || $amount < $rule['max_amount'];
    }

    public function isAutomatable(SuggestionKind $kind): bool
    {
        return $kind->isDraftEntity() || in_array($kind, [SuggestionKind::AccountingCoding, SuggestionKind::ReconciliationMatch], true);
    }
}
