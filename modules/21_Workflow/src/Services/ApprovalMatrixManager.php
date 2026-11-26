<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Workflow\Http\Requests\UpsertApprovalMatrixRequest;
use Modules\Workflow\Models\ApprovalMatrix;
use Modules\Workflow\Models\ApprovalMatrixRule;
use Modules\Workflow\Models\ApprovalMatrixTier;

/**
 * Creates, changes and lists the approval matrices of the active tenant. A matrix is written as a whole (conditions and
 * tiers replace the previous ones in one transaction), so the evaluator never sees a half-saved table.
 *
 * The API speaks subject type codes (`purchase_order`); the table keeps the document class the modules use, or the code
 * itself when the subject has no class yet. Conditions are saved with the stored operator symbols (see
 * {@see ApprovalMatrixEvaluatorService::normalizeOperator()}).
 */
final readonly class ApprovalMatrixManager
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private ApprovableRegistry $registry, private TenantContext $tenantContext) {}

    /**
     * Matrices of the tenant, highest priority first.
     *
     * @return LengthAwarePaginator<int, ApprovalMatrix>
     */
    public function paginate(?string $subjectType, int $perPage): LengthAwarePaginator
    {
        return ApprovalMatrix::query()
            ->where('tenant_id', (string) $this->tenantContext->getTenantId())
            ->when($subjectType !== null, fn ($query) => $query->whereIn('target_entity_type', $this->storedTypes((string) $subjectType)))
            ->with(['rules', 'tiers'])
            ->orderBy('priority')
            ->orderBy('code')
            ->paginate($perPage);
    }

    /**
     * Creates or replaces a matrix.
     *
     * @param  array<string, mixed>  $data  validated payload of {@see UpsertApprovalMatrixRequest}
     */
    public function upsert(array $data, ?ApprovalMatrix $matrix, ?string $userId): ApprovalMatrix
    {
        $tenantId = (string) $this->tenantContext->getTenantId();

        return DB::transaction(function () use ($data, $matrix, $userId, $tenantId): ApprovalMatrix {
            $subject = $this->registry->resolve((string) $data['subject_type']) ?? (string) $data['subject_type'];
            $fallback = $data['fallback_approver'] ?? null;

            $attributes = [
                'name' => $data['name'],
                'target_entity_type' => $this->storedType($subject),
                'currency' => $data['currency'] ?? null,
                'priority' => (int) ($data['priority'] ?? 100),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'is_default' => (bool) ($data['is_default'] ?? false),
                'auto_approve_below' => $data['auto_approve_below'] ?? null,
                'fallback_approver_type' => $fallback['approver_type'] ?? null,
                'fallback_approver_value' => $fallback['approver_value'] ?? null,
                'updated_by' => $userId,
            ];

            if ($matrix === null) {
                $matrix = ApprovalMatrix::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'code' => $data['code'] ?? $this->nextCode($tenantId, $subject),
                    'created_by' => $userId,
                    ...$attributes,
                ]);
            } else {
                $matrix->update($attributes);
                $matrix->rules()->delete();
                $matrix->tiers()->delete();
            }

            if ((bool) $attributes['is_default']) {
                // One explicit default rule per subject.
                ApprovalMatrix::query()
                    ->where('tenant_id', $tenantId)
                    ->where('target_entity_type', $attributes['target_entity_type'])
                    ->where('id', '!=', $matrix->id)
                    ->update(['is_default' => false]);
            }

            foreach ((array) ($data['conditions'] ?? []) as $condition) {
                $this->writeCondition($matrix, $condition);
            }

            foreach ((array) ($data['tiers'] ?? []) as $tier) {
                ApprovalMatrixTier::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'approval_matrix_id' => $matrix->id,
                    'tier_level' => (int) $tier['tier_level'],
                    'approver_type' => $tier['approver_type'],
                    'approver_value' => (string) ($tier['approver_value'] ?? ''),
                    'approval_type' => $tier['approval_type'] ?? 'or_parallel',
                    'threshold_min' => $tier['threshold_min'] ?? null,
                    'threshold_max' => $tier['threshold_max'] ?? null,
                    'threshold_min_inclusive' => (bool) ($tier['threshold_min_inclusive'] ?? true),
                ]);
            }

            return $matrix->load(['rules', 'tiers']);
        });
    }

    /**
     * Soft-deletes a matrix; instances already running keep the approvers they were given.
     */
    public function delete(ApprovalMatrix $matrix): void
    {
        DB::transaction(function () use ($matrix): void {
            $matrix->update(['is_active' => false]);
            $matrix->delete();
        });
    }

    /**
     * The subject type code a stored `target_entity_type` stands for.
     */
    public function subjectOf(ApprovalMatrix $matrix): string
    {
        return $this->registry->resolve((string) $matrix->target_entity_type) ?? (string) $matrix->target_entity_type;
    }

    /**
     * @param  array{field: string, operator: string, value: mixed}  $condition
     */
    private function writeCondition(ApprovalMatrix $matrix, array $condition): void
    {
        $operator = ApprovalMatrixEvaluatorService::normalizeOperator((string) $condition['operator']);
        $value = $condition['value'] ?? null;

        ApprovalMatrixRule::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $matrix->tenant_id,
            'approval_matrix_id' => $matrix->id,
            'field_name' => $condition['field'],
            'operator' => $operator,
            'value_string' => is_array($value) || $value === null ? null : (is_bool($value) ? ($value ? '1' : '0') : (string) $value),
            'value_numeric' => ! is_array($value) && is_numeric($value) ? $value : null,
            'value_json' => is_array($value) ? array_values(Arr::flatten($value)) : null,
        ]);
    }

    /**
     * The next free `MX-<SUBJECT>-<n>` code of the tenant.
     */
    private function nextCode(string $tenantId, string $subject): string
    {
        $base = 'MX-'.strtoupper($subject);
        $count = ApprovalMatrix::withTrashed()->where('tenant_id', $tenantId)->where('code', 'like', $base.'-%')->count();

        do {
            $code = $base.'-'.(++$count);
        } while (ApprovalMatrix::withTrashed()->where('tenant_id', $tenantId)->where('code', $code)->exists());

        return $code;
    }

    private function storedType(string $subject): string
    {
        try {
            return $this->registry->classFor($subject);
        } catch (\InvalidArgumentException) {
            return $subject;
        }
    }

    /**
     * @return list<string>
     */
    private function storedTypes(string $subject): array
    {
        $code = $this->registry->resolve($subject) ?? $subject;

        return array_values(array_unique([$subject, $code, $this->storedType($code)]));
    }
}
