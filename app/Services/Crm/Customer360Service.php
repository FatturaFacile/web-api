<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\Ticket;
use Modules\CRM\Models\Activity;
use Modules\CRM\Services\ConsentService;
use Modules\Invoicing\Models\CustomerCreditProfile;

/**
 * 360-degree customer dashboard: open ledger balance, commercial credit limit and headroom, quote history, orders in
 * progress, delivery notes waiting to be invoiced, issued invoices, open support tickets and recent interactions.
 * Read-only: nothing is evaluated or persisted (the credit profile is read as-is).
 */
class Customer360Service
{
    /** Order statuses that are neither draft nor closed. */
    private const ORDERS_IN_PROGRESS = ['confirmed', 'in_production', 'in_fulfillment', 'partially_delivered', 'shipped'];

    private const OPEN_TICKET_STATUSES = ['open', 'assigned', 'in_progress', 'pending', 'pending_customer'];

    public function __construct(
        private readonly ContactInsights $insights,
        private readonly ContactHierarchyService $hierarchy,
        private readonly ConsentService $consents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(Contact $contact, bool $includeGroup = false): array
    {
        $scope = $includeGroup ? $this->hierarchy->groupIds($contact) : [$contact->id];
        $statement = $this->openItems($contact, $scope);

        return [
            'contact' => [
                'id' => $contact->id,
                'type' => $contact->type,
                'name' => $contact->company_name ?: trim("{$contact->first_name} {$contact->last_name}"),
                'country' => $contact->country,
                'vat_number' => $contact->vat_number,
                'fiscal_code' => $contact->fiscal_code,
                'uid_number' => $contact->uid_number,
                'sdi_code' => $contact->sdi_code,
                'pec' => $contact->pec,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'parent_id' => $contact->parent_id,
                'hierarchy_type' => $contact->hierarchy_type,
                'fiscal_verified_at' => $contact->fiscal_verified_at?->toISOString(),
                'fiscal_verification_source' => $contact->fiscal_verification_source,
            ],
            'scope' => $includeGroup ? 'group' : 'contact',
            'scope_contact_ids' => $scope,
            'receivables' => $statement,
            'credit' => $this->credit($contact, $statement['total_due'], $scope),
            'quotes' => $this->quotes($scope),
            'orders_in_progress' => $this->ordersInProgress($scope),
            'delivery_notes_to_invoice' => $this->deliveryNotesToInvoice($scope),
            'invoices' => $this->invoices($scope),
            'open_tickets' => $this->openTickets($scope),
            'recent_interactions' => $this->interactions($contact),
            'privacy' => $this->privacy($contact),
            'counts' => [
                'addresses' => $contact->addresses()->count(),
                'persons' => $contact->persons()->count(),
                'subsidiaries' => $contact->children()->count(),
            ],
        ];
    }

    /**
     * Open ledger items aggregated over the scope (a single customer or the whole group).
     *
     * @param  array<int, string>  $scope
     * @return array<string, mixed>
     */
    private function openItems(Contact $contact, array $scope): array
    {
        $lines = [];
        $total = 0.0;
        $overdue = 0.0;
        $ageing = array_fill_keys(['not_due', '1_30', '31_60', '61_90', 'over_90'], 0.0);

        foreach ($scope as $id) {
            $member = $id === $contact->id ? $contact : Contact::find($id);

            if ($member === null) {
                continue;
            }

            $statement = $this->insights->statement($member);
            $total += $statement['total_due'];
            $overdue += $statement['overdue_total'];

            foreach ($statement['ageing'] as $bucket => $amount) {
                $ageing[$bucket] += $amount;
            }

            foreach ($statement['lines'] as $line) {
                $lines[] = [...$line, 'contact_id' => $id];
            }
        }

        return [
            'total_due' => round($total, 2),
            'overdue_total' => round($overdue, 2),
            'ageing' => array_map(fn (float $amount): float => round($amount, 2), $ageing),
            'open_items_count' => count($lines),
            'open_items' => $lines,
        ];
    }

    /**
     * Commercial credit: the granted limit, what is already exposed (open invoices + orders not yet billed) and the
     * residual headroom. `limit` is null when no limit was ever granted to the customer.
     *
     * @param  array<int, string>  $scope
     * @return array<string, mixed>
     */
    private function credit(Contact $contact, float $openBalance, array $scope): array
    {
        $profile = CustomerCreditProfile::where('customer_id', $contact->id)->first();
        $limit = $profile !== null ? (float) $profile->credit_limit : null;

        $pendingOrders = (float) SalesOrder::whereIn('contact_id', $scope)
            ->whereIn('status', self::ORDERS_IN_PROGRESS)
            ->where(fn ($q) => $q->whereNull('billing_status')->orWhere('billing_status', '!=', 'billed'))
            ->sum('total_gross');

        $exposure = round($openBalance + $pendingOrders, 2);
        $hasLimit = $limit !== null && $limit > 0;

        return [
            'credit_limit' => $limit,
            'open_balance' => round($openBalance, 2),
            'pending_orders' => round($pendingOrders, 2),
            'exposure' => $exposure,
            'residual' => $hasLimit ? round($limit - $exposure, 2) : null,
            'utilisation_percent' => $hasLimit ? round($exposure / $limit * 100, 1) : null,
            'over_limit' => $hasLimit && $exposure > $limit,
            'credit_status' => $profile?->credit_status,
            'risk_rating' => $profile?->risk_rating,
            'is_credit_hold' => (bool) ($profile->is_credit_hold ?? false),
            'hold_reason' => $profile?->hold_reason,
        ];
    }

    /**
     * @param  array<int, string>  $scope
     * @return array<string, mixed>
     */
    private function quotes(array $scope): array
    {
        $base = Quote::whereIn('contact_id', $scope);

        return [
            'total' => (clone $base)->count(),
            'by_status' => (clone $base)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->all(),
            'recent' => (clone $base)->latest('date')->limit(5)->get()
                ->map(fn (Quote $q): array => [
                    'id' => $q->id,
                    'quote_number' => $q->quote_number,
                    'date' => $q->date->toDateString(),
                    'status' => $q->status,
                    'total_gross' => (float) $q->total_gross,
                    'currency' => $q->currency,
                ])->all(),
        ];
    }

    /**
     * @param  array<int, string>  $scope
     * @return array<string, mixed>
     */
    private function ordersInProgress(array $scope): array
    {
        $orders = SalesOrder::whereIn('contact_id', $scope)->whereIn('status', self::ORDERS_IN_PROGRESS)->latest('date')->get();

        return [
            'count' => $orders->count(),
            'total_gross' => round((float) $orders->sum('total_gross'), 2),
            'items' => $orders->take(10)->map(fn (SalesOrder $o): array => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'date' => $o->date->toDateString(),
                'status' => $o->status,
                'total_gross' => (float) $o->total_gross,
                'currency' => $o->currency,
            ])->values()->all(),
        ];
    }

    /**
     * Issued delivery notes (DDT) that have not been invoiced yet.
     *
     * @param  array<int, string>  $scope
     * @return array<string, mixed>
     */
    private function deliveryNotesToInvoice(array $scope): array
    {
        $notes = DeliveryNote::whereIn('contact_id', $scope)->where('status', 'issued')->latest('date')->get();

        return [
            'count' => $notes->count(),
            'items' => $notes->take(10)->map(fn (DeliveryNote $n): array => [
                'id' => $n->id,
                'ddt_number' => $n->ddt_number,
                'date' => $n->date->toDateString(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<int, string>  $scope
     * @return array<string, mixed>
     */
    private function invoices(array $scope): array
    {
        $base = Invoice::whereIn('contact_id', $scope)->where('invoice_type', '!=', 'proforma');

        return [
            'count' => (clone $base)->count(),
            'recent' => (clone $base)->latest('date')->limit(5)->get()
                ->map(fn (Invoice $i): array => [
                    'id' => $i->id,
                    'invoice_number' => $i->invoice_number,
                    'invoice_type' => $i->invoice_type,
                    'date' => $i->date->toDateString(),
                    'due_date' => $i->due_date?->toDateString(),
                    'total_gross' => (float) $i->total_gross,
                    'total_paid' => (float) $i->total_paid,
                    'payment_status' => $i->payment_status,
                    'currency' => $i->currency,
                ])->all(),
        ];
    }

    /**
     * @param  array<int, string>  $scope
     * @return array<string, mixed>
     */
    private function openTickets(array $scope): array
    {
        $tickets = Ticket::whereIn('client_id', $scope)->whereIn('status', self::OPEN_TICKET_STATUSES)->latest()->get();

        return [
            'count' => $tickets->count(),
            'items' => $tickets->take(10)->map(fn (Ticket $t): array => [
                'id' => $t->id,
                'subject' => $t->subject,
                'priority' => $t->priority,
                'status' => $t->status,
                'created_at' => $t->created_at?->toISOString(),
            ])->values()->all(),
        ];
    }

    /**
     * Privacy regime (nLPD for Swiss customers, GDPR otherwise) with the effective state of every consent recorded in the
     * CRM consent register.
     *
     * @return array<string, mixed>
     */
    private function privacy(Contact $contact): array
    {
        return [
            'regime' => $contact->country === 'CH' ? 'nlpd' : 'gdpr',
            'processing_restricted' => $contact->processing_restricted_at !== null,
            'anonymized' => $contact->anonymized_at !== null,
            'consents' => $this->consents->summary($contact->id, null),
        ];
    }

    /**
     * Calls, emails, notes and meetings logged against the customer (same store the mobile app syncs with).
     *
     * @return array<int, array<string, mixed>>
     */
    private function interactions(Contact $contact): array
    {
        return Activity::where('related_type', 'contact')
            ->where('related_id', $contact->id)
            ->whereIn('type', ['call', 'email', 'note', 'meeting'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Activity $a): array => [
                'id' => $a->id,
                'type' => $a->type,
                'subject' => $a->subject,
                'status' => $a->status,
                'outcome' => $a->outcome,
                'occurred_at' => ($a->completed_at ?? $a->created_at)?->toISOString(),
            ])->all();
    }
}
