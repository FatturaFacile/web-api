<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBillingContract;
use Modules\Projects\Models\ProjectBillingEvent;
use Modules\Projects\Models\ProjectRetentionLedgerEntry;
use Modules\Projects\Models\ProjectSalCertificate;
use Modules\Projects\Services\Fiscal\ProjectFiscalBillingStrategyResolver;
use Modules\Projects\Support\BillingStatuses;
use Modules\Projects\Support\Money;
use Modules\Projects\Support\ProjectAuditTrail;
use Modules\Projects\Support\SalCalculator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * SAL progress certificates (Stato Avanzamento Lavori, Akontorechnung / Teilrechnung) of a billing contract (Mod 081).
 *
 * One certificate is open at a time (draft, pending approval or approved and not yet invoiced), so the previous
 * cumulative figures are always those of the last live certificate. Amounts come from SalCalculator and the retention
 * percentage from the fiscal strategy of the contract. The guarantee retention enters the retention ledger when the
 * certificate is invoiced; a certificate that was not invoiced can be cancelled and nothing was ledgered. The invoice of a
 * certificate carries the progress line, the advance deduction line, and the jurisdiction wording (FatturaPA DatiSAL /
 * DatiContratto for Italy, Akontorechnung / Schlussabrechnung for Switzerland).
 */
final readonly class ProjectSalService
{
    private const array OpenStatuses = [BillingStatuses::SalDraft, BillingStatuses::SalPendingApproval, BillingStatuses::SalApproved];

    public function __construct(
        private InvoicingServiceInterface $invoicing,
        private ProjectFiscalBillingStrategyResolver $strategies,
        private ProjectAuditTrail $audit,
    ) {}

    /**
     * @throws ConflictHttpException when the contract is not billable by progress or a certificate is already open
     * @throws ValidationException when the progress does not advance
     */
    public function create(Project $project, ProjectBillingContract $contract, string $periodStart, string $periodEnd, string $cumulativePercent, ?string $notes, string $actorUserId): ProjectSalCertificate
    {
        return DB::transaction(function () use ($project, $contract, $periodStart, $periodEnd, $cumulativePercent, $notes, $actorUserId): ProjectSalCertificate {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $contract = ProjectBillingContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $this->assertBillable($project, $contract);

            $live = ProjectSalCertificate::query()->where('billing_contract_id', $contract->id)->where('status', '!=', BillingStatuses::SalCancelled);

            if ((clone $live)->whereIn('status', self::OpenStatuses)->exists()) {
                throw new ConflictHttpException(__('project_billing.errors.sal_open_exists'));
            }

            /** @var ProjectSalCertificate|null $last */
            $last = (clone $live)->orderByDesc('sal_number')->first();

            if ($last !== null && $last->is_final) {
                throw new ConflictHttpException(__('project_billing.errors.sal_already_final'));
            }

            $strategy = $this->strategies->forContract($contract);

            try {
                $figures = SalCalculator::compute(
                    (string) $contract->contract_total_value,
                    $cumulativePercent,
                    (string) ($last->cumulative_completion_percent ?? '0'),
                    (string) ($last->cumulative_gross_earned_value ?? '0'),
                    $contract->advanceTotal(),
                    $this->advanceRecouped($contract),
                    $strategy->effectiveRetentionPercent($contract),
                );
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['cumulative_completion_percent' => [__('project_billing.errors.completion_not_advancing')]]);
            }

            $sal = ProjectSalCertificate::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'billing_contract_id' => $contract->id,
                'sal_number' => ($last->sal_number ?? 0) + 1,
                'period_start_date' => $periodStart,
                'period_end_date' => $periodEnd,
                'previous_completion_percent' => Money::round($last->cumulative_completion_percent ?? 0, 4),
                'cumulative_completion_percent' => Money::round($cumulativePercent, 4),
                'cumulative_gross_earned_value' => $figures['cumulative_gross_earned_value'],
                'previous_sal_cumulative_billed' => Money::amount($last->cumulative_gross_earned_value ?? 0),
                'current_period_gross_sal' => $figures['current_period_gross_sal'],
                'advance_deduction_amount' => $figures['advance_deduction_amount'],
                'guarantee_retention_withheld' => $figures['guarantee_retention_withheld'],
                'invoice_amount' => $figures['invoice_amount'],
                'net_billable_amount' => $figures['net_billable_amount'],
                'is_final' => $figures['is_final'],
                'status' => BillingStatuses::SalDraft,
                'notes' => $notes,
                'created_by' => $actorUserId,
            ]);

            $this->syncAdvanceRecouped($contract);
            $this->audit->record($project, $actorUserId, 'sal_created', "SAL {$sal->sal_number} drafted at {$sal->cumulative_completion_percent}%", [], $sal->only(['current_period_gross_sal', 'advance_deduction_amount', 'guarantee_retention_withheld', 'net_billable_amount']), $sal);

            return $sal;
        });
    }

    /**
     * @throws ConflictHttpException when the certificate is not a draft
     */
    public function submit(Project $project, ProjectSalCertificate $sal, string $actorUserId): ProjectSalCertificate
    {
        return DB::transaction(function () use ($project, $sal, $actorUserId): ProjectSalCertificate {
            $sal = ProjectSalCertificate::query()->whereKey($sal->id)->where('project_id', $project->id)->lockForUpdate()->firstOrFail();

            if ($sal->status !== BillingStatuses::SalDraft) {
                throw new ConflictHttpException(__('project_billing.errors.sal_invalid_transition', ['status' => $sal->status]));
            }

            $sal->update(['status' => BillingStatuses::SalPendingApproval]);
            $this->audit->record($project, $actorUserId, 'sal_submitted', "SAL {$sal->sal_number} sent to the customer for approval", [], [], $sal);

            return $sal;
        });
    }

    /**
     * Records the customer approval and drafts the invoice of the certificate.
     *
     * @throws ConflictHttpException when the certificate is cancelled or already invoiced, or the contract is not billable
     */
    public function approveAndInvoice(Project $project, ProjectBillingContract $contract, ProjectSalCertificate $sal, string $taxRate, ?string $approvedBy, string $actorUserId): ProjectSalCertificate
    {
        return DB::transaction(function () use ($project, $contract, $sal, $taxRate, $approvedBy, $actorUserId): ProjectSalCertificate {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $contract = ProjectBillingContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $sal = ProjectSalCertificate::query()->whereKey($sal->id)->where('billing_contract_id', $contract->id)->lockForUpdate()->firstOrFail();
            $this->assertBillable($project, $contract);

            if (! in_array($sal->status, self::OpenStatuses, true)) {
                throw new ConflictHttpException(__('project_billing.errors.sal_invalid_transition', ['status' => $sal->status]));
            }

            $strategy = $this->strategies->forContract($contract);
            $label = "{$project->project_number} {$project->name}";
            $sal->forceFill(['status' => BillingStatuses::SalApproved, 'customer_approved_at' => now(), 'customer_approved_by' => $approvedBy]);

            $items = [[
                'description' => $strategy->progressLineDescription($contract, $sal, $label),
                'quantity' => 1,
                'unit_price' => Money::amount($sal->current_period_gross_sal),
                'tax_rate' => $taxRate,
                'discount_percentage' => 0,
            ]];

            if (Money::isPositive($sal->advance_deduction_amount)) {
                $items[] = [
                    'description' => $strategy->advanceLineDescription($sal),
                    'quantity' => 1,
                    'unit_price' => Money::amount(Money::neg($sal->advance_deduction_amount)),
                    'tax_rate' => $taxRate,
                    'discount_percentage' => 0,
                ];
            }

            $header = $strategy->invoiceHeader($contract, $sal, $label);
            $invoice = $this->invoicing->createInvoice([
                'tenant_id' => (string) $project->tenant_id,
                'contact_id' => (string) $project->contact_id,
                'project_id' => (string) $project->id,
                'currency' => $contract->currency_code,
                'jurisdiction' => $contract->fiscal_jurisdiction,
                'notes' => $header['notes'],
                'created_by' => $actorUserId,
            ], $items);

            if (isset($header['sdi_extra'])) {
                SalesInvoice::query()->whereKey($invoice->id)->toBase()->update(['sdi_extra' => json_encode($header['sdi_extra'], JSON_THROW_ON_ERROR)]);
            }

            $sal->update(['status' => BillingStatuses::SalInvoiced, 'sales_invoice_id' => $invoice->id]);

            $event = ProjectBillingEvent::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'event_type' => 'sal',
                'source_reference_id' => $sal->id,
                'billing_amount' => Money::amount($sal->invoice_amount),
                'status' => 'invoiced',
                'invoice_id' => $invoice->id,
            ]);

            if (Money::isPositive($sal->guarantee_retention_withheld)) {
                ProjectRetentionLedgerEntry::create([
                    'tenant_id' => $project->tenant_id,
                    'company_id' => $project->company_id,
                    'project_id' => $project->id,
                    'billing_contract_id' => $contract->id,
                    'sal_certificate_id' => $sal->id,
                    'entry_type' => ProjectRetentionLedgerEntry::Withheld,
                    'amount' => Money::round($sal->guarantee_retention_withheld, 4),
                    'entry_date' => $sal->period_end_date->toDateString(),
                    'reason' => "SAL {$sal->sal_number}",
                    'billing_event_id' => $event->id,
                    'created_by' => $actorUserId,
                ]);
            }

            if ($sal->is_final) {
                $contract->update(['billing_status' => 'completed', 'version' => $contract->version + 1]);
            }

            $this->audit->record($project, $actorUserId, 'sal_invoiced', "SAL {$sal->sal_number} approved and invoiced", [], ['invoice_id' => $invoice->id, 'net_billable_amount' => $sal->net_billable_amount], $sal);

            return $sal->refresh();
        });
    }

    /**
     * Cancels a certificate that has not been invoiced; its progress and advance recoupment are released.
     *
     * @throws ConflictHttpException when the certificate is already invoiced or cancelled
     */
    public function cancel(Project $project, ProjectBillingContract $contract, ProjectSalCertificate $sal, ?string $reason, string $actorUserId): ProjectSalCertificate
    {
        return DB::transaction(function () use ($project, $contract, $sal, $reason, $actorUserId): ProjectSalCertificate {
            $contract = ProjectBillingContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $sal = ProjectSalCertificate::query()->whereKey($sal->id)->where('billing_contract_id', $contract->id)->lockForUpdate()->firstOrFail();

            if (! in_array($sal->status, self::OpenStatuses, true)) {
                throw new ConflictHttpException(__('project_billing.errors.sal_invalid_transition', ['status' => $sal->status]));
            }

            $sal->update(['status' => BillingStatuses::SalCancelled, 'notes' => $reason ?? $sal->notes]);
            $this->syncAdvanceRecouped($contract);
            $this->audit->record($project, $actorUserId, 'sal_cancelled', "SAL {$sal->sal_number} cancelled", [], ['reason' => $reason], $sal);

            return $sal;
        });
    }

    /**
     * Advance already recouped by the live certificates of the contract.
     */
    public function advanceRecouped(ProjectBillingContract $contract): string
    {
        return Money::amount((string) ProjectSalCertificate::query()
            ->where('billing_contract_id', $contract->id)
            ->where('status', '!=', BillingStatuses::SalCancelled)
            ->sum('advance_deduction_amount'));
    }

    private function syncAdvanceRecouped(ProjectBillingContract $contract): void
    {
        $contract->update(['advance_recouped_amount' => $this->advanceRecouped($contract)]);
    }

    private function assertBillable(Project $project, ProjectBillingContract $contract): void
    {
        if (! $project->isOpen()) {
            throw new ConflictHttpException(__('projects_errors.billing.project_closed', ['number' => $project->project_number, 'status' => $project->status]));
        }

        if (! $contract->isBillable()) {
            throw new ConflictHttpException(__('project_billing.errors.contract_not_billable'));
        }

        if (! $contract->model()->billsProgress()) {
            throw new ConflictHttpException(__('project_billing.errors.model_not_allowed', ['model' => $contract->billing_model]));
        }
    }
}
