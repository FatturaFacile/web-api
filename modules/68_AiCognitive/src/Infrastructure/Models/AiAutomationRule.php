<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\AiCognitive\Domain\Enums\DataClassification;

/**
 * Per workflow policy of a tenant: when the layer may act without a human (confidence at or above the threshold and an
 * amount below the limit) and when the item goes to the review workbench.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $workflow
 * @property bool $is_enabled
 * @property bool $auto_approve_enabled
 * @property string $auto_approve_min_confidence
 * @property string|null $max_auto_amount
 * @property DataClassification $data_classification
 * @property string|null $preferred_provider
 */
final class AiAutomationRule extends BaseEnterpriseModel
{
    protected $table = 'ai_automation_rules';

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'auto_approve_enabled' => 'boolean',
            'auto_approve_min_confidence' => 'decimal:2',
            'max_auto_amount' => 'decimal:2',
            'data_classification' => DataClassification::class,
        ];
    }
}
