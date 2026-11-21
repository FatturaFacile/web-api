<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Handlers;

use App\Models\Tenant;
use Modules\Hse\Application\Commands\SaveSafetyConceptAuditCommand;
use Modules\Hse\Domain\Enums\Jurisdiction;
use Modules\Hse\Domain\Exceptions\HseException;
use Modules\Hse\Infrastructure\Models\SafetyConceptAudit;

/**
 * Scores the 10-point MSSL/ASA safety concept (yes = 1, partial = 0.5, no = 0) for Swiss tenants. A point answered
 * "no" or "partial" must be covered by a corrective action, otherwise the audit stays open without a plan.
 */
final class SaveSafetyConceptAuditHandler
{
    public const int Points = 10;

    private const array Weights = ['yes' => 1.0, 'partial' => 0.5, 'no' => 0.0];

    public function handle(SaveSafetyConceptAuditCommand $command): SafetyConceptAudit
    {
        if (Jurisdiction::forCountry(Tenant::query()->findOrFail($command->tenantId)->country) !== Jurisdiction::Switzerland) {
            throw new HseException('The 10-point MSSL/ASA audit applies to Swiss tenants.');
        }

        $points = array_map('strval', array_keys($command->answers));
        sort($points, SORT_NATURAL);
        $expected = array_map('strval', range(1, self::Points));
        sort($expected, SORT_NATURAL);

        if ($points !== $expected) {
            throw new HseException('The audit needs an answer for each of the 10 points.');
        }

        foreach ($command->answers as $answer) {
            if (! isset(self::Weights[$answer])) {
                throw new HseException('An answer is yes, partial or no.');
            }
        }

        $score = array_sum(array_map(fn (string $answer): float => self::Weights[$answer], $command->answers)) / self::Points * 100;
        $gaps = array_filter($command->answers, fn (string $answer): bool => $answer !== 'yes');

        return SafetyConceptAudit::create([
            'tenant_id' => $command->tenantId,
            'audit_type' => SafetyConceptAudit::MsslTenPoint,
            'jurisdiction' => Jurisdiction::Switzerland,
            'audit_date' => $command->auditDate->toDateString(),
            'answers_json' => $command->answers,
            'score_percent' => round($score, 2),
            'corrective_actions_json' => $command->correctiveActions,
            'status' => $gaps === [] ? 'closed' : 'open',
        ]);
    }
}
