<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\Hse\Domain\Enums\Jurisdiction;

/**
 * A CFST 6508 / MSSL / ASA 10-point safety concept self-assessment (CH) with its score and corrective action plan.
 *
 * @property string $audit_type
 * @property Jurisdiction $jurisdiction
 * @property Carbon $audit_date
 * @property array<string, mixed>|null $answers_json
 * @property numeric-string $score_percent
 * @property list<array<string, mixed>>|null $corrective_actions_json
 * @property string $status
 */
final class SafetyConceptAudit extends BaseEnterpriseModel
{
    public const string MsslTenPoint = 'mssl_10_point';

    protected $table = 'hse_safety_audits';

    protected function casts(): array
    {
        return [
            'jurisdiction' => Jurisdiction::class,
            'audit_date' => 'date',
            'answers_json' => 'array',
            'corrective_actions_json' => 'array',
            'score_percent' => 'decimal:2',
        ];
    }
}
