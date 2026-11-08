<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBillingEvent;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Models\ProjectMilestone;
use Modules\Projects\Models\ProjectTimesheet;
use Modules\Projects\Support\Money;
use Modules\Projects\Support\ProjectAuditTrail;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Billing of a project into draft sales invoices, through the invoicing service.
 *
 * Milestone billing invoices a completed milestone once. Time and materials billing invoices the approved billable
 * hours at their billing rate and the approved billable costs at cost, up to a date, and marks each of them with the
 * invoice. Drafts carry a placeholder number: the fiscal number comes from the gapless sequence of Invoicing when the
 * draft is issued, never from here. When a draft is deleted, the hours and costs it carried become billable again.
 */
final readonly class ProjectBillingService
{
    /** Billing methods under which approved billable hours and costs are recharged to the customer. */
    public const array TimeAndMaterialsMethods = ['time_and_materials', 'time_material', 'cost_plus'];

    public function __construct(
        private InvoicingServiceInterface $invoicing,
        private ProjectAuditTrail $audit,
    ) {}

    /**
     * @throws ConflictHttpException when the milestone is not completed, already billed, or the project is closed
     * @throws ValidationException when the milestone has no billing amount
     */
    public function billMilestone(Project $project, ProjectMilestone $milestone, string $taxRate, string $actorUserId): SalesInvoice
    {
        return DB::transaction(function () use ($project, $milestone, $taxRate, $actorUserId): SalesInvoice {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $milestone = ProjectMilestone::query()->whereKey($milestone->id)->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
            $this->assertBillable($project);

            if (! $milestone->is_completed) {
                throw new ConflictHttpException('The milestone is not completed yet: complete it before billing.');
            }

            if ($milestone->is_billed && $this->invoiceStillExists($milestone->invoice_id)) {
                throw new ConflictHttpException('The milestone is already billed.');
            }

            $amount = Money::isPositive($milestone->billing_amount)
                ? Money::amount($milestone->billing_amount)
                : Money::amount(Money::div(Money::mul($project->contract_value, $milestone->billing_percentage), 100));

            if (! Money::isPositive($amount)) {
                throw ValidationException::withMessages(['milestone' => 'The milestone has no billing amount or percentage.']);
            }

            $invoice = $this->invoicing->createInvoice($this->header($project, $actorUserId, "Milestone {$milestone->name}", $milestone->id), [[
                'description' => "{$project->project_number} {$project->name} - milestone {$milestone->name}",
                'quantity' => 1,
                'unit_price' => $amount,
                'tax_rate' => $taxRate,
                'discount_percentage' => 0,
            ]]);

            $milestone->update(['is_billed' => true, 'billed_at' => now(), 'invoice_id' => $invoice->id]);
            $this->recordEvent($project, 'milestone', $milestone->id, $amount, $invoice, $actorUserId);

            return $invoice;
        });
    }

    /**
     * Invoices the unbilled approved billable hours and costs up to the given date.
     *
     * @throws ValidationException when there is nothing to bill
     */
    public function billTimeAndMaterials(Project $project, string $upToDate, string $taxRate, string $actorUserId): SalesInvoice
    {
        return DB::transaction(function () use ($project, $upToDate, $taxRate, $actorUserId): SalesInvoice {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->assertBillable($project);

            $timesheets = $this->unbilledTimesheets($project)->whereDate('work_date', '<=', $upToDate)->lockForUpdate()->orderBy('work_date')->get();
            $costs = $this->unbilledCosts($project)->whereDate('incurred_date', '<=', $upToDate)->lockForUpdate()->orderBy('incurred_date')->get();

            if ($timesheets->isEmpty() && $costs->isEmpty()) {
                throw ValidationException::withMessages(['up_to_date' => 'There are no approved billable hours or costs to invoice up to this date.']);
            }

            $names = User::query()->whereIn('id', $timesheets->pluck('user_id')->unique())->pluck('name', 'id');
            $items = [];
            $total = Money::of(0);

            foreach ($timesheets->groupBy(static fn (ProjectTimesheet $sheet): string => $sheet->user_id.'|'.$sheet->charge_rate) as $group) {
                /** @var ProjectTimesheet $first */
                $first = $group->first();
                $hours = Money::add(...$group->map(static fn (ProjectTimesheet $sheet): string => (string) $sheet->hours_worked)->all());
                $amount = Money::add(...$group->map(static fn (ProjectTimesheet $sheet): string => (string) $sheet->total_charge)->all());
                $total = Money::add($total, $amount);

                $items[] = [
                    'description' => sprintf('%s - hours of %s (%s to %s)', $project->project_number, $names[$first->user_id] ?? 'staff', $group->min('work_date')?->toDateString(), $group->max('work_date')?->toDateString()),
                    'quantity' => Money::round($hours, 2),
                    'unit_price' => Money::amount($first->charge_rate),
                    'tax_rate' => $taxRate,
                    'discount_percentage' => 0,
                    'uom' => 'HR',
                ];
            }

            foreach ($costs as $cost) {
                $total = Money::add($total, $cost->total_cost);
                $items[] = [
                    'description' => sprintf('%s - %s (%s)', $project->project_number, $cost->description ?? $cost->cost_category, $cost->incurred_date->toDateString()),
                    'quantity' => 1,
                    'unit_price' => Money::amount($cost->total_cost),
                    'tax_rate' => $taxRate,
                    'discount_percentage' => 0,
                ];
            }

            $invoice = $this->invoicing->createInvoice($this->header($project, $actorUserId, "Time and materials up to {$upToDate}", null), $items);

            ProjectTimesheet::query()->whereIn('id', $timesheets->pluck('id'))->update(['invoice_id' => $invoice->id]);
            ProjectCostEntry::query()->whereIn('id', $costs->pluck('id'))->update(['invoice_id' => $invoice->id, 'is_invoiced' => true]);
            $this->recordEvent($project, 'time_and_materials', null, Money::amount($total), $invoice, $actorUserId);

            return $invoice;
        });
    }

    /**
     * Approved billable hours not on an existing invoice.
     *
     * @return Builder<ProjectTimesheet>
     */
    public function unbilledTimesheets(Project $project): Builder
    {
        return ProjectTimesheet::query()
            ->where('project_id', $project->id)
            ->where('status', 'approved')
            ->where('is_billable', true)
            ->where(fn (Builder $query) => $query->whereNull('invoice_id')->orWhereDoesntHave('invoice'));
    }

    /**
     * Approved billable costs, other than the labour of timesheets (billed as hours), not on an existing invoice.
     *
     * @return Builder<ProjectCostEntry>
     */
    public function unbilledCosts(Project $project): Builder
    {
        return ProjectCostEntry::query()
            ->approved()
            ->where('project_id', $project->id)
            ->where('is_billable', true)
            ->where('cost_source_type', '!=', 'Timesheet')
            ->where(fn (Builder $query) => $query->whereNull('invoice_id')->orWhereDoesntHave('invoice'));
    }

    /**
     * Whether the contract recharges hours and costs to the customer (time and materials or cost plus); a fixed price
     * or milestone contract is invoiced by milestones, whatever the billable flag of its costs.
     */
    public function billsTimeAndMaterials(Project $project): bool
    {
        return in_array($project->billing_method, self::TimeAndMaterialsMethods, true);
    }

    /**
     * Value still to invoice: unbilled billable hours at billing rate plus unbilled billable costs.
     */
    public function unbilledValue(Project $project): string
    {
        $hours = (string) $this->unbilledTimesheets($project)->sum('total_charge');
        $costs = (string) $this->unbilledCosts($project)->sum('total_cost');

        return Money::amount(Money::add($hours, $costs));
    }

    /**
     * Completed billable milestones whose invoice was never created or was deleted.
     */
    public function unbilledMilestoneCount(Project $project): int
    {
        return ProjectMilestone::query()
            ->where('project_id', $project->id)
            ->where('is_completed', true)
            ->where(fn (Builder $query) => $query->where('billing_amount', '>', 0)->orWhere('billing_percentage', '>', 0))
            ->get()
            ->filter(fn (ProjectMilestone $milestone): bool => ! $milestone->is_billed || ! $this->invoiceStillExists($milestone->invoice_id))
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Project $project, string $actorUserId, string $what, ?string $milestoneId): array
    {
        return [
            'tenant_id' => (string) $project->tenant_id,
            'contact_id' => (string) $project->contact_id,
            'project_id' => (string) $project->id,
            'project_milestone_id' => $milestoneId,
            'currency' => $project->currency ?: 'EUR',
            'notes' => "Project {$project->project_number}: {$what}",
            'created_by' => $actorUserId,
        ];
    }

    private function recordEvent(Project $project, string $type, ?string $sourceId, string $amount, SalesInvoice $invoice, string $actorUserId): void
    {
        ProjectBillingEvent::create([
            'tenant_id' => $project->tenant_id,
            'company_id' => $project->company_id,
            'project_id' => $project->id,
            'event_type' => $type,
            'source_reference_id' => $sourceId,
            'billing_amount' => $amount,
            'status' => 'invoiced',
            'invoice_id' => $invoice->id,
        ]);

        $this->audit->record($project, $actorUserId, 'billing_drafted', "Draft invoice for {$type} billing of {$amount}", [], ['invoice_id' => $invoice->id, 'amount' => $amount], $project);
    }

    private function assertBillable(Project $project): void
    {
        if (! $project->isOpen()) {
            throw new ConflictHttpException("Project {$project->project_number} is {$project->status}: it can no longer be billed.");
        }
    }

    private function invoiceStillExists(?string $invoiceId): bool
    {
        return $invoiceId !== null && SalesInvoice::query()->whereKey($invoiceId)->exists();
    }
}
