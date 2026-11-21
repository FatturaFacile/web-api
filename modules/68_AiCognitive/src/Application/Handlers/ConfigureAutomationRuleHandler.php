<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Handlers;

use Modules\AiCognitive\Application\Commands\ConfigureAutomationRuleCommand;
use Modules\AiCognitive\Infrastructure\Models\AiAutomationRule;
use Modules\AiCognitive\Infrastructure\Services\AiAuditor;
use Modules\AiCognitive\Infrastructure\Services\AiTenantRunner;

final readonly class ConfigureAutomationRuleHandler
{
    public function __construct(private AiTenantRunner $runner, private AiAuditor $auditor) {}

    public function handle(ConfigureAutomationRuleCommand $command): AiAutomationRule
    {
        return $this->runner->run($command->tenantId, function () use ($command): AiAutomationRule {
            $rule = AiAutomationRule::query()->where('tenant_id', $command->tenantId)->where('workflow', $command->workflow)->first()
                ?? new AiAutomationRule(['tenant_id' => $command->tenantId, 'workflow' => $command->workflow]);

            $rule->fill([
                'is_enabled' => $command->enabled,
                'auto_approve_enabled' => $command->autoApprove,
                'auto_approve_min_confidence' => round($command->minConfidence, 2),
                'max_auto_amount' => $command->maxAutoAmount,
                'data_classification' => $command->classification,
                'preferred_provider' => $command->preferredProvider,
            ])->save();

            $this->auditor->record($command->tenantId, 'automation_rule_configured', $command->userId, 'ai_automation_rule', $rule->id, null, null, null, [
                'workflow' => $command->workflow,
                'enabled' => $command->enabled,
                'auto_approve' => $command->autoApprove,
                'min_confidence' => round($command->minConfidence, 2),
                'max_auto_amount' => $command->maxAutoAmount,
            ]);

            return $rule->refresh();
        });
    }
}
