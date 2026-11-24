<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Hse\Domain\Contracts\HseClearanceGatekeeperInterface;
use Modules\Hse\Domain\Enums\BlockingReasonCode;
use Modules\Hse\Domain\Enums\CertificateStatus;
use Modules\Hse\Domain\Enums\ClearanceContext;
use Modules\Hse\Domain\Enums\FitnessOutcome;
use Modules\Hse\Domain\Enums\PpeDeliveryStatus;
use Modules\Hse\Domain\Exceptions\HseNotFoundException;
use Modules\Hse\Domain\ValueObjects\BlockingReason;
use Modules\Hse\Domain\ValueObjects\ClearanceRequest;
use Modules\Hse\Domain\ValueObjects\ClearanceResult;
use Modules\Hse\Infrastructure\Models\EmployeeSafetyCertificate;
use Modules\Hse\Infrastructure\Models\OccupationalMedicalVisit;
use Modules\Hse\Infrastructure\Models\PpeDistributionRecord;
use Modules\Hse\Infrastructure\Models\SafetyRoleRequirementMatrix;
use Modules\HumanResources\Models\Employee;

/**
 * The hard safety block. It collects the active requirement matrices that apply to the employee and the target (every
 * scope a matrix defines must match: its job position, work center and construction site), merges their demands and
 * checks the employee against them as of the scheduled moment. Any expired, missing or unfit item blocks the dispatch.
 *
 * The judgment of the physician is also checked when no matrix applies: a worker judged unfit is never cleared. A
 * prescription tagged "[BLOCK:<context or certificate type>]" in the restrictions summary conflicts with a dispatch to
 * that context or that kind of work; any other prescription is returned as a warning for the dispatcher.
 */
final class HseClearanceGatekeeper implements HseClearanceGatekeeperInterface
{
    public function evaluate(ClearanceRequest $request): ClearanceResult
    {
        $employee = Employee::query()->where('tenant_id', $request->tenantId)->whereKey($request->employeeId)->first()
            ?? throw new HseNotFoundException('Unknown employee.');

        $matrices = $this->matchingMatrices($request, $employee);
        $day = $request->scheduledAt->startOfDay();

        [$medicalReasons, $warnings] = $this->medical($request, $matrices, $day);
        $reasons = [
            ...$this->certificateReasons($request, $matrices, $day),
            ...$medicalReasons,
            ...$this->ppeReasons($request, $matrices, $day),
        ];

        return ClearanceResult::fromReasons($reasons, $warnings);
    }

    /**
     * @return Collection<int, SafetyRoleRequirementMatrix>
     */
    private function matchingMatrices(ClearanceRequest $request, Employee $employee): Collection
    {
        return SafetyRoleRequirementMatrix::query()
            ->where('tenant_id', $request->tenantId)
            ->where('is_active', true)
            ->get()
            ->filter(function (SafetyRoleRequirementMatrix $matrix) use ($request, $employee): bool {
                if ($matrix->job_position_id !== null && $matrix->job_position_id !== $employee->position_id) {
                    return false;
                }

                if ($matrix->work_center_id !== null && ! ($request->context !== ClearanceContext::ConstructionSite && $matrix->work_center_id === $request->targetId)) {
                    return false;
                }

                return $matrix->construction_site_id === null || ($request->context === ClearanceContext::ConstructionSite && $matrix->construction_site_id === $request->targetId);
            })
            ->values();
    }

    /**
     * @param  Collection<int, SafetyRoleRequirementMatrix>  $matrices
     * @return list<BlockingReason>
     */
    private function certificateReasons(ClearanceRequest $request, Collection $matrices, CarbonImmutable $day): array
    {
        /** @var array<string, int|null> $required type value => strictest max age in months */
        $required = [];

        foreach ($matrices as $matrix) {
            foreach ($matrix->requiredTrainings() as $training) {
                $key = $training['type']->value;
                $months = $training['validity_months'];
                $required[$key] = array_key_exists($key, $required) ? $this->strictest($required[$key], $months) : $months;
            }
        }

        if ($required === []) {
            return [];
        }

        $certificates = EmployeeSafetyCertificate::query()
            ->where('tenant_id', $request->tenantId)->where('employee_id', $request->employeeId)
            ->whereIn('certificate_type', array_keys($required))
            ->get()->groupBy(fn (EmployeeSafetyCertificate $certificate): string => $certificate->certificate_type->value);

        $reasons = [];

        foreach ($required as $type => $maxAgeMonths) {
            /** @var Collection<int, EmployeeSafetyCertificate> $held */
            $held = $certificates->get($type, collect())
                ->filter(fn (EmployeeSafetyCertificate $certificate): bool => $certificate->revoked_at === null && $certificate->status !== CertificateStatus::Revoked
                    && CarbonImmutable::parse($certificate->issued_at->toDateString())->lte($day));

            if ($held->isEmpty()) {
                $reasons[] = new BlockingReason(BlockingReasonCode::CertificateMissing, $type);

                continue;
            }

            $valid = $held->first(function (EmployeeSafetyCertificate $certificate) use ($day, $maxAgeMonths): bool {
                if ($certificate->statusAt($day) === CertificateStatus::Expired) {
                    return false;
                }

                return $maxAgeMonths === null || CarbonImmutable::parse($certificate->issued_at->toDateString())->addMonths($maxAgeMonths)->gte($day);
            });

            if ($valid === null) {
                $latest = $held->sortByDesc(fn (EmployeeSafetyCertificate $certificate): string => $certificate->expires_at?->toDateString() ?? '9999-12-31')->first();
                $reasons[] = new BlockingReason(BlockingReasonCode::CertificateExpired, $type, $latest?->expires_at?->toDateString());
            }
        }

        return $reasons;
    }

    /**
     * @param  Collection<int, SafetyRoleRequirementMatrix>  $matrices
     * @return array{0: list<BlockingReason>, 1: list<string>} the blocking reasons and the warnings
     */
    private function medical(ClearanceRequest $request, Collection $matrices, CarbonImmutable $day): array
    {
        $warnings = [];
        $examsRequired = $matrices->contains(fn (SafetyRoleRequirementMatrix $matrix): bool => ($matrix->required_medical_exam_codes_json ?? []) !== []);

        /** @var OccupationalMedicalVisit|null $visit */
        $visit = OccupationalMedicalVisit::query()
            ->where('tenant_id', $request->tenantId)->where('employee_id', $request->employeeId)
            ->whereDate('visit_date', '<=', $day->toDateString())
            ->orderByDesc('visit_date')->orderByDesc('created_at')->first();

        if ($visit === null) {
            return [$examsRequired ? [new BlockingReason(BlockingReasonCode::MedicalMissing, 'medical_fitness')] : [], $warnings];
        }

        if ($visit->fitness_outcome->blocksDispatch()) {
            return [[new BlockingReason(BlockingReasonCode::MedicalUnfit, 'medical_fitness', $visit->next_visit_due_date?->toDateString(), $visit->fitness_outcome->value)], $warnings];
        }

        $reasons = [];

        if ($examsRequired && $visit->next_visit_due_date !== null && CarbonImmutable::parse($visit->next_visit_due_date->toDateString())->lt($day)) {
            $reasons[] = new BlockingReason(BlockingReasonCode::MedicalExpired, 'medical_fitness', $visit->next_visit_due_date->toDateString());
        }

        if ($visit->fitness_outcome === FitnessOutcome::FitWithPrescriptions && $visit->restrictions_summary !== null) {
            $blocked = $this->blockedTags($request, $matrices);
            preg_match_all('/\[BLOCK:([a-z0-9_]+)\]/i', $visit->restrictions_summary, $found);

            if (array_intersect(array_map('strtolower', $found[1]), $blocked) !== []) {
                $reasons[] = new BlockingReason(BlockingReasonCode::MedicalRestrictionConflict, 'medical_fitness', null, $visit->restrictions_summary);
            } else {
                $warnings[] = $visit->restrictions_summary;
            }
        }

        return [$reasons, $warnings];
    }

    /**
     * @param  Collection<int, SafetyRoleRequirementMatrix>  $matrices
     * @return list<string>
     */
    private function blockedTags(ClearanceRequest $request, Collection $matrices): array
    {
        $tags = [$request->context->value];

        foreach ($matrices as $matrix) {
            foreach ($matrix->requiredTrainings() as $training) {
                $tags[] = $training['type']->value;
            }
        }

        return array_values(array_unique(array_map('strtolower', $tags)));
    }

    /**
     * @param  Collection<int, SafetyRoleRequirementMatrix>  $matrices
     * @return list<BlockingReason>
     */
    private function ppeReasons(ClearanceRequest $request, Collection $matrices, CarbonImmutable $day): array
    {
        $productIds = $matrices->flatMap(fn (SafetyRoleRequirementMatrix $matrix): array => $matrix->required_ppe_product_ids_json ?? [])->unique()->values();

        if ($productIds->isEmpty()) {
            return [];
        }

        $records = PpeDistributionRecord::query()
            ->where('tenant_id', $request->tenantId)->where('employee_id', $request->employeeId)
            ->whereIn('product_id', $productIds->all())
            ->where('delivery_status', PpeDeliveryStatus::DeliveredSigned->value)
            ->whereDate('handed_over_at', '<=', $day->toDateString())
            ->get()->groupBy('product_id');

        $reasons = [];

        foreach ($productIds as $productId) {
            $held = $records->get($productId, collect());
            $current = $held->first(fn (PpeDistributionRecord $record): bool => $record->replacement_due_at === null || CarbonImmutable::parse($record->replacement_due_at->toDateString())->gte($day));

            if ($current === null) {
                $latest = $held->sortByDesc(fn (PpeDistributionRecord $record): string => $record->replacement_due_at?->toDateString() ?? '')->first();
                $reasons[] = new BlockingReason(BlockingReasonCode::PpeMissing, (string) $productId, $latest?->replacement_due_at?->toDateString(), $latest === null ? null : 'replacement_overdue');
            }
        }

        return $reasons;
    }

    private function strictest(?int $a, ?int $b): ?int
    {
        return $a === null ? $b : ($b === null ? $a : min($a, $b));
    }
}
