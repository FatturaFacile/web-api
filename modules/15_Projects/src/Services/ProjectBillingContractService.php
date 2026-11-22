<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBillingContract;
use Modules\Projects\Services\Fiscal\ProjectFiscalBillingStrategyResolver;
use Modules\Projects\Support\BillingModel;
use Modules\Projects\Support\BillingStatuses;
use Modules\Projects\Support\Money;
use Modules\Projects\Support\ProjectAuditTrail;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Setup and life cycle of the billing contract of a project (Mod 081). A project has at most one contract; updates carry
 * the version they read (optimistic concurrency) and a closed contract is frozen.
 */
final readonly class ProjectBillingContractService
{
    /** Allowed status moves; a closed contract is final. */
    private const array Transitions = [
        'draft' => ['active', 'closed'],
        'active' => ['on_hold', 'completed', 'closed'],
        'on_hold' => ['active', 'closed'],
        'completed' => ['closed', 'active'],
        'closed' => [],
    ];

    public function __construct(
        private ProjectFiscalBillingStrategyResolver $strategies,
        private ProjectAuditTrail $audit,
    ) {}

    /**
     * Creates the contract of the project, or updates it when the expected version matches.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ConflictHttpException when the contract was changed by someone else or is closed
     * @throws ValidationException when the terms are inconsistent with the billing model
     */
    public function upsert(Project $project, array $attributes, ?int $expectedVersion, string $actorUserId): ProjectBillingContract
    {
        return DB::transaction(function () use ($project, $attributes, $expectedVersion, $actorUserId): ProjectBillingContract {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $contract = ProjectBillingContract::query()->where('project_id', $project->id)->lockForUpdate()->first();

            if ($contract !== null) {
                if ($expectedVersion === null || $expectedVersion !== $contract->version) {
                    throw new ConflictHttpException(__('project_billing.errors.version_conflict'));
                }

                if ($contract->billing_status === 'closed') {
                    throw new ConflictHttpException(__('project_billing.errors.contract_closed'));
                }
            }

            $currency = strtoupper((string) ($attributes['currency_code'] ?? $contract->currency_code ?? $project->currency ?: 'EUR'));
            $values = $attributes + ($contract?->getAttributes() ?? []);
            $values['currency_code'] = $currency;
            $values['fiscal_jurisdiction'] = strtoupper((string) ($attributes['fiscal_jurisdiction'] ?? $contract->fiscal_jurisdiction ?? $this->strategies->defaultJurisdictionForCurrency($currency)));
            $values['contract_total_value'] = $attributes['contract_total_value'] ?? $contract->contract_total_value ?? $project->contract_value;

            $this->assertConsistent($values);

            $payload = [
                'customer_id' => (string) $project->contact_id,
                'billing_model' => (string) $values['billing_model'],
                'fiscal_jurisdiction' => $values['fiscal_jurisdiction'],
                'currency_code' => $currency,
                'contract_total_value' => Money::round($values['contract_total_value'], 4),
                'advance_payment_percent' => Money::round($values['advance_payment_percent'] ?? 0, 4),
                'advance_payment_amount' => Money::round($values['advance_payment_amount'] ?? 0, 4),
                'retention_guarantee_percent' => Money::round($values['retention_guarantee_percent'] ?? 0, 4),
                'retention_release_rule' => (string) ($values['retention_release_rule'] ?? 'manual'),
                'retention_release_date' => $values['retention_release_date'] ?? null,
                'cap_amount' => isset($values['cap_amount']) ? Money::round($values['cap_amount'], 4) : null,
                'markup_percent' => Money::round($values['markup_percent'] ?? 0, 4),
                'rate_card_id' => $values['rate_card_id'] ?? null,
                'prepaid_hours_allowance' => Money::round($values['prepaid_hours_allowance'] ?? 0, 4),
                'prepaid_amount_allowance' => Money::round($values['prepaid_amount_allowance'] ?? 0, 4),
                'low_balance_alert_threshold_percent' => Money::round($values['low_balance_alert_threshold_percent'] ?? 10, 4),
                'cig' => $values['cig'] ?? null,
                'cup' => $values['cup'] ?? null,
                'contract_reference' => $values['contract_reference'] ?? null,
                'contract_date' => $values['contract_date'] ?? null,
            ];

            if ($contract === null) {
                $contract = ProjectBillingContract::create($payload + [
                    'tenant_id' => $project->tenant_id,
                    'company_id' => $project->company_id,
                    'project_id' => $project->id,
                    'billing_status' => 'draft',
                    'version' => 1,
                    'created_by' => $actorUserId,
                ]);
                $this->audit->record($project, $actorUserId, 'billing_contract_created', "Billing contract {$contract->billing_model} created", [], $payload, $contract);

                return $contract;
            }

            $before = $contract->only(array_keys($payload));
            $contract->update($payload + ['version' => $contract->version + 1]);
            $this->audit->record($project, $actorUserId, 'billing_contract_updated', 'Billing contract updated', $before, $payload, $contract);

            return $contract->refresh();
        });
    }

    /**
     * @throws ConflictHttpException when the move is not allowed, or the contract has no value to bill
     */
    public function transition(Project $project, ProjectBillingContract $contract, string $status, ?int $expectedVersion, string $actorUserId): ProjectBillingContract
    {
        return DB::transaction(function () use ($project, $contract, $status, $expectedVersion, $actorUserId): ProjectBillingContract {
            $contract = ProjectBillingContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();

            if ($expectedVersion !== null && $expectedVersion !== $contract->version) {
                throw new ConflictHttpException(__('project_billing.errors.version_conflict'));
            }

            if (! in_array($status, self::Transitions[$contract->billing_status] ?? [], true)) {
                throw new ConflictHttpException(__('project_billing.errors.invalid_status_transition', ['from' => $contract->billing_status, 'to' => $status]));
            }

            if ($status === 'active' && ! Money::isPositive($contract->contract_total_value) && $contract->model()->billsProgress()) {
                throw new ConflictHttpException(__('project_billing.errors.contract_value_required'));
            }

            $from = $contract->billing_status;
            $contract->update(['billing_status' => $status, 'version' => $contract->version + 1]);
            $this->audit->record($project, $actorUserId, 'billing_contract_status', "Billing contract {$from} -> {$status}", ['billing_status' => $from], ['billing_status' => $status], $contract);

            return $contract->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    private function assertConsistent(array $values): void
    {
        $errors = [];
        $model = BillingModel::tryFrom((string) ($values['billing_model'] ?? ''));

        if ($model === null) {
            $errors['billing_model'] = [__('project_billing.errors.unknown_model')];
        } else {
            if ($model->billsProgress() && ! Money::isPositive($values['contract_total_value'] ?? 0)) {
                $errors['contract_total_value'] = [__('project_billing.errors.contract_value_required')];
            }

            if ($model === BillingModel::PrepaidRetainer && ! Money::isPositive($values['prepaid_hours_allowance'] ?? 0) && ! Money::isPositive($values['prepaid_amount_allowance'] ?? 0)) {
                $errors['prepaid_hours_allowance'] = [__('project_billing.errors.retainer_allowance_required')];
            }
        }

        if (Money::compare($values['advance_payment_percent'] ?? 0, 100) > 0 || Money::compare($values['retention_guarantee_percent'] ?? 0, 100) > 0) {
            $errors['advance_payment_percent'] = [__('project_billing.errors.percent_out_of_range')];
        }

        if (! in_array($values['retention_release_rule'] ?? 'manual', BillingStatuses::RetentionReleaseRules, true)) {
            $errors['retention_release_rule'] = [__('project_billing.errors.unknown_release_rule')];
        }

        if (($values['retention_release_rule'] ?? 'manual') === 'on_warranty_expiry' && empty($values['retention_release_date'])) {
            $errors['retention_release_date'] = [__('project_billing.errors.warranty_date_required')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
