<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBillingContract;
use Modules\Projects\Models\ProjectBillingEvent;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Models\ProjectRateCard;
use Modules\Projects\Models\ProjectTimesheet;
use Modules\Projects\Support\Money;
use Modules\Projects\Support\ProjectAuditTrail;
use Modules\Projects\Support\TimeAndMaterialsCalculator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Draft invoices of the time and materials of a billing contract (Mod 081).
 *
 * Sources are the approved billable timesheets (Mod 080), and the approved billable cost entries: reimbursable expense
 * reports (Mod 075) and job-linked supplier bills (Mod 048) reach the project as cost entries, so the contract needs no
 * other adapter. Hours are billed at the rate card of the contract, else at the rate frozen on the timesheet; costs carry
 * the contract markup (ricarico). Non-billable entries never appear. Under a retainer the hours the bank covers are burned
 * first and not invoiced. The cap of the contract bounds the total billed: lines are cut at it, and the sources of a cut
 * line count as billed, the part over the cap being waived.
 */
final readonly class ProjectTimeAndMaterialsBillingService
{
    public function __construct(
        private InvoicingServiceInterface $invoicing,
        private ProjectBillingService $billing,
        private ProjectRetainerService $retainer,
        private ProjectAuditTrail $audit,
    ) {}

    /**
     * The lines a draft up to the date would carry, without creating anything.
     *
     * @return array{lines: list<array<string, mixed>>, hours_total: string, labour_total: string, costs_total: string, markup_total: string, billable_total: string, capped_out: string, retainer_covered_hours: string}
     */
    public function preview(Project $project, ProjectBillingContract $contract, string $upToDate): array
    {
        $plan = $this->plan($project, $contract, $upToDate, '0', false);

        return [
            'lines' => array_map(static fn (array $line): array => collect($line)->except(['source'])->all(), $plan['lines']),
            'hours_total' => $plan['hours_total'],
            'labour_total' => $plan['labour_total'],
            'costs_total' => $plan['costs_total'],
            'markup_total' => $plan['markup_total'],
            'billable_total' => $plan['billable_total'],
            'capped_out' => $plan['capped_out'],
            'retainer_covered_hours' => $plan['retainer_covered_hours'],
        ];
    }

    /**
     * @throws ConflictHttpException when the contract is not active or does not bill time and materials
     * @throws ValidationException when there is nothing to bill
     */
    public function draftInvoice(Project $project, ProjectBillingContract $contract, string $upToDate, string $taxRate, string $actorUserId): SalesInvoice
    {
        return DB::transaction(function () use ($project, $contract, $upToDate, $taxRate, $actorUserId): SalesInvoice {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $contract = ProjectBillingContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $this->assertBillable($project, $contract);

            $plan = $this->plan($project, $contract, $upToDate, $actorUserId, true);
            $billed = array_values(array_filter($plan['lines'], static fn (array $line): bool => Money::isPositive($line['amount'])));

            if ($billed === []) {
                throw ValidationException::withMessages(['up_to_date' => [__($plan['capped_out'] !== '0.00' ? 'project_billing.errors.cap_reached' : 'project_billing.errors.nothing_to_bill')]]);
            }

            $items = array_map(static fn (array $line): array => [
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_rate' => $taxRate,
                'discount_percentage' => 0,
                'uom' => $line['uom'],
            ], $billed);

            $invoice = $this->invoicing->createInvoice([
                'tenant_id' => (string) $project->tenant_id,
                'contact_id' => (string) $project->contact_id,
                'project_id' => (string) $project->id,
                'currency' => $contract->currency_code,
                'jurisdiction' => $contract->fiscal_jurisdiction,
                'notes' => __('project_billing.labels.tm_notes', ['number' => $project->project_number, 'date' => $upToDate]),
                'created_by' => $actorUserId,
            ], $items);

            $timesheetIds = [];
            $costIds = [];

            foreach ($billed as $line) {
                $timesheetIds = [...$timesheetIds, ...$line['source']['timesheets']];
                $costIds = [...$costIds, ...$line['source']['costs']];
            }

            ProjectTimesheet::query()->whereIn('id', $timesheetIds)->update(['invoice_id' => $invoice->id]);
            ProjectCostEntry::query()->whereIn('id', $costIds)->update(['invoice_id' => $invoice->id, 'is_invoiced' => true]);

            $total = Money::add(...array_column($billed, 'amount'));
            ProjectBillingEvent::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'event_type' => 'time_and_materials',
                'source_reference_id' => $contract->id,
                'billing_amount' => Money::amount($total),
                'status' => 'invoiced',
                'invoice_id' => $invoice->id,
            ]);

            $this->audit->record($project, $actorUserId, 'billing_drafted', "Draft T&M invoice of {$total} up to {$upToDate}", [], ['invoice_id' => $invoice->id, 'capped_out' => $plan['capped_out']], $contract);

            return $invoice;
        });
    }

    /**
     * Amount billed so far under the contract by time and materials drafts that still exist.
     */
    public function billedSoFar(Project $project): string
    {
        return Money::amount((string) ProjectBillingEvent::query()
            ->where('project_id', $project->id)
            ->where('event_type', 'time_and_materials')
            ->whereHas('invoice')
            ->sum('billing_amount'));
    }

    /**
     * @return array{lines: list<array<string, mixed>>, hours_total: string, labour_total: string, costs_total: string, markup_total: string, billable_total: string, capped_out: string, retainer_covered_hours: string}
     */
    private function plan(Project $project, ProjectBillingContract $contract, string $upToDate, string $actorUserId, bool $burnRetainer): array
    {
        $covered = '0';

        if ($contract->model()->usesRetainer() && $burnRetainer) {
            $covered = $this->retainer->consume($project, $contract, $upToDate, $actorUserId)['consumed_hours'];
        }

        $consumed = $contract->model()->usesRetainer() ? $this->retainer->consumedTimesheetIds($contract) : [];
        $timesheets = $this->billing->unbilledTimesheets($project)->whereDate('work_date', '<=', $upToDate)->whereNotIn('id', $consumed)->orderBy('work_date')->get();
        $costs = $this->billing->unbilledCosts($project)->whereDate('incurred_date', '<=', $upToDate)->orderBy('incurred_date')->get();
        $rate = $contract->rate_card_id !== null ? ProjectRateCard::query()->find($contract->rate_card_id)?->bill_rate : null;
        $names = User::query()->whereIn('id', $timesheets->pluck('user_id')->unique())->pluck('name', 'id');

        $lines = [];

        foreach ($timesheets->groupBy(static fn (ProjectTimesheet $sheet): string => $sheet->user_id.'|'.($rate ?? $sheet->charge_rate)) as $group) {
            /** @var ProjectTimesheet $first */
            $first = $group->first();
            $unit = Money::amount($rate ?? $first->charge_rate);
            $hours = Money::round(Money::add(...$group->map(static fn (ProjectTimesheet $sheet): string => (string) $sheet->hours_worked)->all()), 2);
            $lines[] = [
                'kind' => 'hours',
                'description' => sprintf('%s - %s (%s - %s)', $project->project_number, $names[$first->user_id] ?? __('project_billing.labels.staff'), $group->min('work_date')?->toDateString(), $group->max('work_date')?->toDateString()),
                'quantity' => $hours,
                'unit_price' => $unit,
                'uom' => 'HR',
                'hours' => $hours,
                'gross' => Money::amount(Money::mul($hours, $unit)),
                'cost' => Money::amount(Money::mul($hours, $unit)),
                'source' => ['timesheets' => $group->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all(), 'costs' => []],
            ];
        }

        foreach ($costs as $cost) {
            $base = Money::amount($cost->total_cost);
            $lines[] = [
                'kind' => 'cost',
                'description' => sprintf('%s - %s (%s)', $project->project_number, $cost->description ?? $cost->cost_category, $cost->incurred_date->toDateString()),
                'quantity' => '1.00',
                'unit_price' => TimeAndMaterialsCalculator::withMarkup($base, (string) $contract->markup_percent),
                'uom' => null,
                'hours' => '0.00',
                'gross' => TimeAndMaterialsCalculator::withMarkup($base, (string) $contract->markup_percent),
                'cost' => $base,
                'source' => ['timesheets' => [], 'costs' => [(string) $cost->id]],
            ];
        }

        $cap = TimeAndMaterialsCalculator::applyCap(array_column($lines, 'gross'), $contract->cap_amount, $this->billedSoFar($project));
        $hoursTotal = Money::of(0);
        $labour = Money::of(0);
        $costTotal = Money::of(0);
        $markup = Money::of(0);

        foreach ($lines as $index => &$line) {
            $line['amount'] = $cap['billed'][$index];

            if (Money::compare($line['amount'], $line['gross']) < 0) {
                $line['quantity'] = '1.00';
                $line['unit_price'] = $line['amount'];
                $line['uom'] = null;
                $line['description'] .= ' ['.__('project_billing.labels.capped').']';
            }

            if ($line['kind'] === 'hours') {
                $labour = Money::add($labour, $line['amount']);
                $hoursTotal = Money::add($hoursTotal, $line['hours']);
            } else {
                $costTotal = Money::add($costTotal, $line['cost']);
                $markup = Money::add($markup, Money::sub($line['amount'], $line['cost']));
            }
        }
        unset($line);

        return [
            'lines' => $lines,
            'hours_total' => Money::round($hoursTotal, 2),
            'labour_total' => Money::amount($labour),
            'costs_total' => Money::amount($costTotal),
            'markup_total' => Money::amount($markup),
            'billable_total' => Money::amount(Money::add(...array_column($lines, 'amount'))),
            'capped_out' => $cap['capped_out'],
            'retainer_covered_hours' => Money::round($covered, 2),
        ];
    }

    private function assertBillable(Project $project, ProjectBillingContract $contract): void
    {
        if (! $project->isOpen()) {
            throw new ConflictHttpException(__('projects_errors.billing.project_closed', ['number' => $project->project_number, 'status' => $project->status]));
        }

        if (! $contract->isBillable()) {
            throw new ConflictHttpException(__('project_billing.errors.contract_not_billable'));
        }

        if (! $contract->model()->billsTimeAndMaterials()) {
            throw new ConflictHttpException(__('project_billing.errors.model_not_allowed', ['model' => $contract->billing_model]));
        }
    }
}
