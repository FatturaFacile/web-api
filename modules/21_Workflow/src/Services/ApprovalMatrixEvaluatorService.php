<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Contracts\ApprovalMatrixEvaluatorInterface;
use Modules\Workflow\Models\ApprovalMatrix;
use Modules\Workflow\Models\ApprovalMatrixRule;

/**
 * Picks the approvers of a document from the tiered approval matrices of the tenant.
 *
 * A matrix matches when ALL its conditions (rules) hold. Matrices are evaluated in priority order (lowest number first,
 * a cost-centre matrix before a generic one of the same priority) and the first match with tiers wins. When no ordinary
 * matrix produces approvers, the explicit default matrix (`is_default`) is tried and then its fallback approver; if there
 * is still nobody the result is empty and the engine blocks the instance: there is never an implicit approval.
 *
 * Tiers are cumulative: a tier applies to every amount inside its range. The lower bound is inclusive unless the tier
 * says otherwise (`threshold_min_inclusive = false`, e.g. "above 5'000"); the upper bound is always inclusive. Amounts
 * are compared in the functional currency of the company (`amount_functional` of the context when the document is in a
 * foreign currency).
 */
final class ApprovalMatrixEvaluatorService implements ApprovalMatrixEvaluatorInterface
{
    /**
     * Inject the registry that maps subject types to document classes.
     */
    public function __construct(private readonly ApprovableRegistry $registry) {}

    /**
     * {@inheritDoc}
     */
    public function evaluate(ApprovalMatrix $matrix, Model $entity, array $context): array
    {
        return $this->tiersOfMatrix($matrix, $this->valueResolver($entity, $context), $this->amountOf($entity, $context));
    }

    /**
     * {@inheritDoc}
     */
    public function tiersFor(string $tenantId, string $entityType, Model $entity, array $context): ?array
    {
        $matrices = $this->matricesFor($tenantId, $entityType);

        if ($matrices->isEmpty()) {
            return null;
        }

        return $this->pick($matrices, $this->valueResolver($entity, $context), $this->amountOf($entity, $context))['tiers'];
    }

    /**
     * Whether the winning matrix approves the document by itself (`auto_approve_below`, amount strictly below).
     *
     * @param  array<string, mixed>  $context
     */
    public function autoApproves(string $tenantId, string $entityType, ?Model $entity, array $context): bool
    {
        if ($entity === null) {
            return false;
        }

        $matrices = $this->matricesFor($tenantId, $entityType);

        if ($matrices->isEmpty()) {
            return false;
        }

        $picked = $this->pick($matrices, $this->valueResolver($entity, $context), $this->amountOf($entity, $context));

        return $picked['matrix'] !== null && $this->isBelowAutoApproval($picked['matrix'], $this->amountOf($entity, $context));
    }

    /**
     * Dry run for the matrix editor: which matrix and tiers a document with these attributes would get. Nothing is saved
     * and no document is needed.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{matrix: ?ApprovalMatrix, used_default: bool, used_fallback: bool, auto_approved: bool, blocked: bool, tiers: array<int, array{tier_level: int, approver_type: string, approver_value: string, approval_type: string}>}
     */
    public function simulate(string $tenantId, string $subjectType, array $attributes, ?string $matrixId = null): array
    {
        $matrices = $this->matricesFor($tenantId, $subjectType);

        if ($matrixId !== null) {
            $matrices = $matrices->where('id', $matrixId)->values();
        }

        $resolver = static fn (string $field): mixed => $attributes[$field] ?? null;
        $amount = (string) ($attributes['amount_functional'] ?? $attributes['amount'] ?? '0');

        $picked = $this->pick($matrices, $resolver, $amount);
        $matrix = $picked['matrix'];

        return [
            'matrix' => $matrix,
            'used_default' => $picked['used_default'],
            'used_fallback' => $picked['used_fallback'],
            'auto_approved' => $matrix !== null && $this->isBelowAutoApproval($matrix, $amount),
            'blocked' => $picked['tiers'] === [] && ($matrix === null || ! $this->isBelowAutoApproval($matrix, $amount)),
            'tiers' => $picked['tiers'],
        ];
    }

    /**
     * Active matrices of a subject in evaluation order. The subject may be given as a class or as a subject code: both
     * spellings are stored by the editor and by the modules.
     *
     * @return Collection<int, ApprovalMatrix>
     */
    private function matricesFor(string $tenantId, string $subject): Collection
    {
        $types = array_values(array_unique(array_filter([$subject, $this->registry->resolve($subject), $this->classOf($subject)])));

        return ApprovalMatrix::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('target_entity_type', $types)
            ->where('is_active', true)
            ->with(['rules', 'tiers'])
            ->orderBy('priority')
            ->orderBy('code')
            ->get();
    }

    private function classOf(string $subject): ?string
    {
        $code = $this->registry->resolve($subject);

        if ($code === null) {
            return null;
        }

        try {
            return $this->registry->classFor($code);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * First matching ordinary matrix, then the default one, then its fallback approver.
     *
     * @param  Collection<int, ApprovalMatrix>  $matrices
     * @param  callable(string): mixed  $value
     * @return array{matrix: ?ApprovalMatrix, tiers: array<int, array{tier_level: int, approver_type: string, approver_value: string, approval_type: string}>, used_default: bool, used_fallback: bool}
     */
    private function pick(Collection $matrices, callable $value, string $amount): array
    {
        $ordinary = $matrices->where('is_default', false)->values();
        // Stable sort: matrices that name a cost centre are tried before generic ones of the same priority.
        $ordinary = $ordinary->sortBy(fn (ApprovalMatrix $matrix): array => [$matrix->priority, $matrix->rules->whereNotNull('cost_center_id')->isNotEmpty() ? 0 : 1])->values();

        foreach ($ordinary as $matrix) {
            $tiers = $this->tiersOfMatrix($matrix, $value, $amount);

            if ($tiers !== [] || $this->isBelowAutoApproval($matrix, $amount) && $this->matches($matrix, $value)) {
                return ['matrix' => $matrix, 'tiers' => $tiers, 'used_default' => false, 'used_fallback' => false];
            }
        }

        foreach ($matrices->where('is_default', true) as $matrix) {
            $tiers = $this->tiersOfMatrix($matrix, $value, $amount);

            if ($tiers !== [] || $this->isBelowAutoApproval($matrix, $amount)) {
                return ['matrix' => $matrix, 'tiers' => $tiers, 'used_default' => true, 'used_fallback' => false];
            }
        }

        foreach ($matrices as $matrix) {
            if ($matrix->fallback_approver_type !== null && $matrix->fallback_approver_value !== null && $matrix->fallback_approver_value !== '') {
                return [
                    'matrix' => $matrix,
                    'tiers' => [['tier_level' => 1, 'approver_type' => (string) $matrix->fallback_approver_type, 'approver_value' => (string) $matrix->fallback_approver_value, 'approval_type' => 'or_parallel']],
                    'used_default' => (bool) $matrix->is_default,
                    'used_fallback' => true,
                ];
            }
        }

        return ['matrix' => null, 'tiers' => [], 'used_default' => false, 'used_fallback' => false];
    }

    /**
     * Whether every condition of the matrix holds.
     *
     * @param  callable(string): mixed  $value
     */
    private function matches(ApprovalMatrix $matrix, callable $value): bool
    {
        foreach ($matrix->rules as $rule) {
            if (! $this->evaluateRule($rule, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The tiers of a matrix for an amount; empty when a condition fails or no tier covers the amount.
     *
     * @param  callable(string): mixed  $value
     * @return array<int, array{tier_level: int, approver_type: string, approver_value: string, approval_type: string}>
     */
    private function tiersOfMatrix(ApprovalMatrix $matrix, callable $value, string $amount): array
    {
        if (! $this->matches($matrix, $value)) {
            return [];
        }

        $matchingTiers = [];
        foreach ($matrix->tiers as $tier) {
            if ($tier->threshold_min !== null) {
                $comparison = bccomp($amount, (string) $tier->threshold_min, 4);

                if ($comparison < 0 || ($comparison === 0 && $tier->threshold_min_inclusive === false)) {
                    continue;
                }
            }

            if ($tier->threshold_max !== null && bccomp($amount, (string) $tier->threshold_max, 4) > 0) {
                continue;
            }

            $matchingTiers[] = [
                'tier_level' => (int) $tier->tier_level,
                'approver_type' => (string) $tier->approver_type,
                'approver_value' => (string) $tier->approver_value,
                'approval_type' => (string) $tier->approval_type,
            ];
        }

        return $matchingTiers;
    }

    private function isBelowAutoApproval(ApprovalMatrix $matrix, string $amount): bool
    {
        return $matrix->auto_approve_below !== null && bccomp($amount, (string) $matrix->auto_approve_below, 4) < 0;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function amountOf(Model $entity, array $context): string
    {
        $amount = $context['amount_functional'] ?? $entity->getAttribute('total_amount') ?? $entity->getAttribute('amount') ?? $context['amount'] ?? '0';

        return is_numeric($amount) ? (string) $amount : '0';
    }

    /**
     * @param  array<string, mixed>  $context
     * @return callable(string): mixed
     */
    private function valueResolver(Model $entity, array $context): callable
    {
        return static fn (string $field): mixed => $entity->getAttribute($field) ?? $context[$field] ?? null;
    }

    /**
     * @param  callable(string): mixed  $value
     */
    private function evaluateRule(ApprovalMatrixRule $rule, callable $value): bool
    {
        $actual = $value((string) $rule->field_name);

        if ($rule->cost_center_id !== null && (string) $value('cost_center_id') !== (string) $rule->cost_center_id) {
            return false;
        }

        $expected = $rule->value_string ?? $rule->value_numeric;
        $list = is_array($rule->value_json) ? $rule->value_json : [];

        return match (self::normalizeOperator((string) $rule->operator)) {
            '=' => is_bool($actual) ? $actual === filter_var($expected, FILTER_VALIDATE_BOOLEAN) : (string) $actual === (string) $expected,
            '!=' => is_bool($actual) ? $actual !== filter_var($expected, FILTER_VALIDATE_BOOLEAN) : (string) $actual !== (string) $expected,
            '>' => (float) $actual > (float) $rule->value_numeric,
            '>=' => (float) $actual >= (float) $rule->value_numeric,
            '<' => (float) $actual < (float) $rule->value_numeric,
            '<=' => (float) $actual <= (float) $rule->value_numeric,
            'in' => in_array($actual, $list, false),
            'not_in' => ! in_array($actual, $list, false),
            'between' => count($list) === 2 && (float) $actual >= (float) $list[0] && (float) $actual <= (float) $list[1],
            default => true,
        };
    }

    /**
     * The stored operator symbol of a symbol or of the spelled-out name the API and the editor use.
     */
    public static function normalizeOperator(string $operator): string
    {
        return match ($operator) {
            'eq' => '=',
            'neq' => '!=',
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            default => $operator,
        };
    }
}
