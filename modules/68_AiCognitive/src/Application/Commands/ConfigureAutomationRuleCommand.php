<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Commands;

use Modules\AiCognitive\Domain\Enums\DataClassification;

/**
 * Sets the zero-click policy of one workflow of a tenant: the minimum confidence and the amount below which the layer may
 * act without a person.
 */
final readonly class ConfigureAutomationRuleCommand
{
    public function __construct(
        public string $tenantId,
        public ?string $userId,
        public string $workflow,
        public bool $enabled,
        public bool $autoApprove,
        public float $minConfidence,
        public ?float $maxAutoAmount,
        public DataClassification $classification,
        public ?string $preferredProvider = null,
    ) {}
}
