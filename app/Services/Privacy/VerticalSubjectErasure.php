<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Models\Member;
use Modules\Associations\Services\ErasureService as AssociationsErasureService;
use RuntimeException;

/**
 * Erasure of the data subjects of the vertical modules: association members, children in care, visitors and job
 * applicants. Members go through the associations erasure service, which already checks the fiscal and members book
 * retention; the others follow the rule of the contacts: what the law does not keep is anonymised, what it keeps is
 * reported. Statutory periods are NOT verified here (to be confirmed by the data protection officer).
 */
final readonly class VerticalSubjectErasure
{
    /** Health columns of a child: always erased, even when billing records keep the identity. */
    private const array ChildHealthColumns = ['birth_date', 'allergies', 'dietary_notes', 'health_notes'];

    public function __construct(
        private PersonalDataRegistry $registry,
        private PersonalDataAnonymizer $anonymizer,
        private AssociationsErasureService $associations,
    ) {}

    /**
     * @return array{legal_holds: list<array{statute: string, retention_years: int|null, reason: string}>, erased_fields: list<string>}
     */
    public function erase(string $tenantId, string $subjectType, string $subjectId): array
    {
        return match ($subjectType) {
            'member' => $this->eraseMember($tenantId, $subjectId),
            'child' => $this->eraseChild($tenantId, $subjectId),
            'visitor' => $this->anonymiseEntry('visitors', $tenantId, $subjectId),
            'applicant' => $this->anonymiseEntry('hr_job_applications', $tenantId, $subjectId),
            default => throw new RuntimeException("Subject type [{$subjectType}] has no vertical erasure."),
        };
    }

    /**
     * @return array{legal_holds: list<array{statute: string, retention_years: int|null, reason: string}>, erased_fields: list<string>}
     */
    private function eraseMember(string $tenantId, string $memberId): array
    {
        $member = Member::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($memberId);
        $result = $this->associations->erase($member);

        $holds = array_map(static fn (array $hold): array => [
            'statute' => "Associations register: {$hold['record']}",
            'retention_years' => null,
            'reason' => "{$hold['count']} record(s) kept ({$hold['reason']}), until ".($hold['until'] ?? 'no end date').'.',
        ], $result['kept']);

        return ['legal_holds' => $holds, 'erased_fields' => $result['erased']];
    }

    /**
     * @return array{legal_holds: list<array{statute: string, retention_years: int|null, reason: string}>, erased_fields: list<string>}
     */
    private function eraseChild(string $tenantId, string $childId): array
    {
        $billing = 0;
        foreach (['cc_monthly_bills', 'cc_child_fees'] as $table) {
            $billing += DB::table($table)->where('tenant_id', $tenantId)->where('child_id', $childId)->count();
        }

        $holds = [];
        if ($billing > 0) {
            $holds[] = [
                'statute' => 'Childcare billing records: Swiss CO art. 958f (10 years), to be confirmed',
                'retention_years' => StatutoryRetentionCatalogue::FISCAL_RECORD_YEARS,
                'reason' => "The child has {$billing} billing record(s): the identity is kept, the health data are erased.",
            ];
        }

        $result = $this->anonymizer->anonymize(
            $this->registry->findOrFail('cc_children'),
            static function (Builder $query) use ($tenantId, $childId): void {
                $query->where('tenant_id', $tenantId)->where('id', $childId);
            },
            $holds === [] ? null : self::ChildHealthColumns,
        );

        return ['legal_holds' => $holds, 'erased_fields' => $result['columns']];
    }

    /**
     * @return array{legal_holds: list<array{statute: string, retention_years: int|null, reason: string}>, erased_fields: list<string>}
     */
    private function anonymiseEntry(string $key, string $tenantId, string $subjectId): array
    {
        $result = $this->anonymizer->anonymize(
            $this->registry->findOrFail($key),
            static function (Builder $query) use ($tenantId, $subjectId): void {
                $query->where('tenant_id', $tenantId)->where('id', $subjectId);
            },
        );

        return ['legal_holds' => [], 'erased_fields' => $result['columns']];
    }
}
