<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\CustomerHealthProfile;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Opportunity;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\WorkOrder;

/**
 * Read model of the customer 360: one account seen across CRM, invoicing, contracts, helpdesk and field service.
 * Every source is scoped to the tenant of the account and, for the operational modules, to the contact (party master)
 * the account points at, so nothing of another tenant can leak into the summary or the timeline.
 */
final class Customer360Service
{
    public const TIMELINE_TYPES = ['activity', 'opportunity', 'invoice', 'ticket', 'work_order', 'contract', 'lead'];

    private const SOURCE_LIMIT = 100;

    private const CLOSED_TICKET_STATUSES = ['resolved', 'closed', 'cancelled'];

    /**
     * @return array<string, mixed>
     */
    public function summary(Account $account): array
    {
        $tenantId = (string) $account->tenant_id;
        $customerId = $account->contact_id !== null ? (string) $account->contact_id : null;

        return [
            'account' => [
                'id' => (string) $account->id,
                'contact_id' => $customerId,
                'name' => $account->name,
                'tier' => $account->tier,
                'status' => $account->status,
            ],
            'health' => $this->health($account),
            'kpis' => array_merge(
                $this->commercialKpis($tenantId, $account, $customerId),
                $this->supportKpis($tenantId, $customerId),
                $this->contractKpis($tenantId, $account, $customerId),
                $this->fieldKpis($tenantId, $customerId),
            ),
        ];
    }

    /**
     * Newest-first merged stream of every touchpoint of the customer.
     *
     * @param  list<string>|null  $types  restricts the stream to some of TIMELINE_TYPES
     * @return list<array{type: string, id: string, occurred_at: string, title: string, status: string|null, amount: string|null, reference: string|null}>
     */
    public function timeline(Account $account, ?array $types = null, ?Carbon $from = null, ?Carbon $to = null, int $limit = 50): array
    {
        $tenantId = (string) $account->tenant_id;
        $customerId = $account->contact_id !== null ? (string) $account->contact_id : null;
        $wanted = $types === null || $types === [] ? self::TIMELINE_TYPES : array_values(array_intersect(self::TIMELINE_TYPES, $types));

        /** @var Collection<int, array{type: string, id: string, occurred_at: Carbon, title: string, status: string|null, amount: string|null, reference: string|null}> $events */
        $events = collect();

        foreach ($wanted as $type) {
            $events = $events->concat($this->events($type, $tenantId, $account, $customerId));
        }

        return $events
            ->filter(fn (array $event): bool => ($from === null || $event['occurred_at']->greaterThanOrEqualTo($from)) && ($to === null || $event['occurred_at']->lessThanOrEqualTo($to)))
            ->sortByDesc(fn (array $event): int => $event['occurred_at']->getTimestamp())
            ->take(max(1, min($limit, 200)))
            ->map(fn (array $event): array => [...$event, 'occurred_at' => $event['occurred_at']->toIso8601String()])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function health(Account $account): array
    {
        $profile = CustomerHealthProfile::query()->where('tenant_id', $account->tenant_id)->where('account_id', $account->id)->first();

        return [
            'health_index' => $profile !== null ? (float) $profile->health_index : (float) $account->customer_health_index,
            'churn_risk' => $profile !== null ? $profile->churn_risk_indicator : $account->churn_risk,
            'last_interaction_at' => $profile !== null && $profile->last_interaction_at !== null ? Carbon::parse($profile->last_interaction_at)->toIso8601String() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commercialKpis(string $tenantId, Account $account, ?string $customerId): array
    {
        $open = Opportunity::query()->where('tenant_id', $tenantId)->where('account_id', $account->id)->where('status', 'open');
        $invoices = $customerId === null ? null : $this->postedInvoices($tenantId, $customerId);

        return [
            'lifetime_value' => $invoices === null ? '0.00' : number_format((float) (clone $invoices)->sum('total_gross'), 2, '.', ''),
            'open_pipeline_value' => number_format((float) (clone $open)->sum('amount'), 2, '.', ''),
            'open_opportunities' => (clone $open)->count(),
            'overdue_receivable_amount' => $invoices === null ? '0.00' : number_format((float) (clone $invoices)
                ->whereColumn('total_gross', '>', 'total_paid')
                ->whereDate('due_date', '<', now()->toDateString())
                ->toBase()
                ->selectRaw('COALESCE(SUM(total_gross - total_paid), 0) AS overdue')
                ->value('overdue'), 2, '.', ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function supportKpis(string $tenantId, ?string $customerId): array
    {
        if ($customerId === null) {
            return ['open_tickets' => 0, 'sla_breach_rate' => 0.0, 'mttr_hours' => null];
        }

        $tickets = Ticket::query()->where('tenant_id', $tenantId)->where('customer_id', $customerId);
        $total = (clone $tickets)->count();
        $breached = (clone $tickets)->where(fn ($query) => $query->whereNotNull('response_breached_at')->orWhereNotNull('resolution_breached_at'))->count();

        $resolved = (clone $tickets)->whereNotNull('resolved_at')->latest('resolved_at')->limit(self::SOURCE_LIMIT)->get(['created_at', 'resolved_at']);
        $mttr = $resolved->isEmpty() ? null : round($resolved->avg(fn (Ticket $ticket): float => abs($ticket->resolved_at->diffInMinutes($ticket->created_at)) / 60), 2);

        return [
            'open_tickets' => (clone $tickets)->whereNotIn('status', self::CLOSED_TICKET_STATUSES)->count(),
            'sla_breach_rate' => $total === 0 ? 0.0 : round($breached / $total * 100, 1),
            'mttr_hours' => $mttr,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contractKpis(string $tenantId, Account $account, ?string $customerId): array
    {
        if ($customerId === null) {
            return ['active_contracts' => 0, 'contract_coverage' => 'none', 'contract_hours_remaining' => '0.00', 'contracts_expiring_soon' => 0];
        }

        $contracts = ServiceContract::query()->where('tenant_id', $tenantId)->whereIn('customer_id', [$customerId, (string) $account->id])->where('status', 'active')->get();
        $remaining = $contracts->reduce(fn (string $carry, ServiceContract $contract): string => bcadd($carry, bcsub((string) $contract->included_hours, (string) $contract->consumed_hours, 2), 2), '0.00');
        $soon = now()->addDays(60);

        return [
            'active_contracts' => $contracts->count(),
            'contract_coverage' => $contracts->isEmpty() ? 'none' : (bccomp($remaining, '0', 2) > 0 ? 'covered' : 'exhausted'),
            'contract_hours_remaining' => $remaining,
            'contracts_expiring_soon' => $contracts->filter(fn (ServiceContract $contract): bool => $contract->end_date !== null && $contract->end_date->lessThanOrEqualTo($soon))->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldKpis(string $tenantId, ?string $customerId): array
    {
        if ($customerId === null) {
            return ['interventions_scheduled' => 0, 'interventions_completed' => 0];
        }

        $orders = WorkOrder::query()->where('tenant_id', $tenantId)->where('customer_id', $customerId);

        return [
            'interventions_scheduled' => (clone $orders)->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'interventions_completed' => (clone $orders)->where('status', 'completed')->count(),
        ];
    }

    /**
     * @return Builder<SalesInvoice>
     */
    private function postedInvoices(string $tenantId, string $customerId)
    {
        return SalesInvoice::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $customerId)
            ->where(fn ($query) => $query->where('status', 'posted')->orWhereNotNull('posted_at'));
    }

    /**
     * @return Collection<int, array{type: string, id: string, occurred_at: Carbon, title: string, status: string|null, amount: string|null, reference: string|null}>
     */
    private function events(string $type, string $tenantId, Account $account, ?string $customerId): Collection
    {
        $none = collect();

        return match ($type) {
            'activity' => Activity::query()->where('tenant_id', $tenantId)->where('related_type', 'account')->where('related_id', $account->id)
                ->latest('created_at')->limit(self::SOURCE_LIMIT)->get()
                ->map(fn (Activity $row): array => $this->event($type, (string) $row->id, $row->completed_at ?? $row->created_at, (string) $row->subject, $row->status, null, $row->type)),
            'opportunity' => Opportunity::query()->where('tenant_id', $tenantId)->where('account_id', $account->id)
                ->latest('created_at')->limit(self::SOURCE_LIMIT)->get()
                ->map(fn (Opportunity $row): array => $this->event($type, (string) $row->id, $row->created_at, (string) $row->name, $row->status, (string) $row->amount, $row->opportunity_number)),
            'lead' => Lead::query()->where('tenant_id', $tenantId)->where('converted_account_id', $account->id)
                ->latest('created_at')->limit(self::SOURCE_LIMIT)->get()
                ->map(fn (Lead $row): array => $this->event($type, (string) $row->id, $row->converted_at ?? $row->created_at, trim(($row->company_name ?: $row->first_name.' '.$row->last_name)), $row->status, null, null)),
            'invoice' => $customerId === null ? $none : SalesInvoice::query()->where('tenant_id', $tenantId)->where('contact_id', $customerId)
                ->latest('date')->limit(self::SOURCE_LIMIT)->get()
                ->map(fn (SalesInvoice $row): array => $this->event($type, (string) $row->id, $row->date, (string) $row->invoice_number, $row->status, (string) $row->total_gross, (string) $row->invoice_number)),
            'ticket' => $customerId === null ? $none : Ticket::query()->where('tenant_id', $tenantId)->where('customer_id', $customerId)
                ->latest('created_at')->limit(self::SOURCE_LIMIT)->get()
                ->map(fn (Ticket $row): array => $this->event($type, (string) $row->id, $row->created_at, (string) $row->subject, $row->status, null, $row->ticket_number)),
            'work_order' => $customerId === null ? $none : WorkOrder::query()->where('tenant_id', $tenantId)->where('customer_id', $customerId)
                ->latest('created_at')->limit(self::SOURCE_LIMIT)->get()
                ->map(fn (WorkOrder $row): array => $this->event($type, (string) $row->id, $row->actual_end ?? $row->created_at, (string) $row->title, $row->status, null, $row->work_order_number)),
            'contract' => $customerId === null ? $none : ServiceContract::query()->where('tenant_id', $tenantId)->whereIn('customer_id', [$customerId, (string) $account->id])
                ->latest('start_date')->limit(self::SOURCE_LIMIT)->get()
                ->map(fn (ServiceContract $row): array => $this->event($type, (string) $row->id, $row->start_date, (string) $row->contract_number, $row->status, null, $row->contract_number)),
            default => $none,
        };
    }

    /**
     * @return array{type: string, id: string, occurred_at: Carbon, title: string, status: string|null, amount: string|null, reference: string|null}
     */
    private function event(string $type, string $id, mixed $occurredAt, string $title, ?string $status, ?string $amount, ?string $reference): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'occurred_at' => Carbon::parse($occurredAt ?? now()),
            'title' => $title,
            'status' => $status,
            'amount' => $amount,
            'reference' => $reference,
        ];
    }
}
