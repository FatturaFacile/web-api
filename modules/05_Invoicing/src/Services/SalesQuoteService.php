<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Enums\QuoteStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Tenant;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Sales\DocumentTotalsCalculator;
use App\Services\Sales\QuoteLifecycleService;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Invoicing\Contracts\PricingAndDiscountServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Contracts\SalesQuoteServiceInterface;
use Modules\Invoicing\Events\SalesQuoteAcceptedEvent;
use Modules\Invoicing\Events\SalesQuoteApprovedEvent;
use Modules\Invoicing\Events\SalesQuoteCreatedEvent;
use Modules\Invoicing\Events\SalesQuoteSubmittedEvent;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesQuote;
use Modules\Invoicing\Models\SalesQuoteItem;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowInstance;

final class SalesQuoteService implements SalesQuoteServiceInterface
{
    public const string ApprovalWorkflow = 'SALES_QUOTE_APPROVAL';

    public const string HighValueApprovalWorkflow = 'HIGH_VALUE_SALES_QUOTE_APPROVAL';

    /**
     * Gross total above which the high value approval workflow is used, when the tenant has defined one.
     */
    public const float HighValueThreshold = 25000.0;

    public function __construct(
        private readonly PricingAndDiscountServiceInterface $pricingService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly DocumentNumberingService $numbering,
        private readonly ?TenantClock $clock = null,
        private readonly ?WorkflowEngineInterface $workflowEngine = null,
        private readonly ?AuditLedgerInterface $auditLedger = null
    ) {}

    /**
     * Creates a draft quote numbered on the `quote` series of the tenant, the same gapless series the sales API of the
     * app uses. The number is reserved in the same transaction as the insert, so a failed insert does not burn it.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createQuote(array $data, array $items): SalesQuote
    {
        $tenantId = (string) $data['tenant_id'];

        $date = CarbonImmutable::parse($data['date'] ?? $this->today($tenantId));
        $calculatedItems = [];
        foreach ($items as $item) {
            if (! isset($item['tax_rate'])) {
                throw new InvalidArgumentException('Every quote line must state its VAT rate.');
            }

            $quantity = DocumentTotalsCalculator::decimal($item['quantity'] ?? '1');
            $pricing = isset($item['product_id']) && (! isset($item['unit_price']) || ! isset($item['discount_percentage']))
                ? $this->pricingService->determineLinePrice((string) $item['product_id'], $quantity, (string) $data['contact_id'], $data['price_list_id'] ?? null, $data['discount_policy_id'] ?? null, $date->toDateString())
                : null;

            $calculatedItems[] = array_merge($item, [
                'quantity' => $quantity,
                'unit_price' => isset($item['unit_price']) ? DocumentTotalsCalculator::decimal($item['unit_price']) : ($pricing['unit_price'] ?? '0'),
                'discount_percentage' => isset($item['discount_percentage']) ? DocumentTotalsCalculator::decimal($item['discount_percentage']) : ($pricing['discount_percentage'] ?? '0'),
                'tax_rate' => DocumentTotalsCalculator::decimal($item['tax_rate']),
            ]);
        }

        $decimalTotals = (new DocumentTotalsCalculator)->calculateDecimal($calculatedItems);
        foreach ($decimalTotals['items'] as $index => $line) {
            $calculatedItems[$index] = array_merge($calculatedItems[$index], $line);
        }
        $totals = [
            'subtotal' => $decimalTotals['subtotal'],
            'discount_total' => $decimalTotals['discount_total'],
            'net_total' => $decimalTotals['total_net'],
            'tax_total' => $decimalTotals['total_tax'],
            'gross_total' => $decimalTotals['total_gross'],
        ];

        $quote = DB::transaction(function () use ($data, $tenantId, $totals, $calculatedItems, $date): SalesQuote {
            $number = $this->numbering->next('quote', $date->year, $tenantId);

            $quote = SalesQuote::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'contact_id' => (string) $data['contact_id'],
                'quote_number' => $number['number'],
                'date' => $date->toDateString(),
                'valid_until' => $data['valid_until'] ?? $date->addDays(30)->toDateString(),
                'currency' => $data['currency'] ?? 'EUR',
                'subtotal' => $totals['subtotal'],
                'discount_amount' => $totals['discount_total'],
                'total_net' => $totals['net_total'],
                'total_tax' => $totals['tax_total'],
                'total_gross' => $totals['gross_total'],
                'status' => QuoteStatus::Draft->value,
                'notes' => $data['notes'] ?? null,
                'discount_policy_id' => $data['discount_policy_id'] ?? null,
                'price_list_id' => $data['price_list_id'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'shipping_terms' => $data['shipping_terms'] ?? null,
                'revision_number' => 1,
                'created_by' => ! empty($data['created_by']) && Str::isUuid((string) $data['created_by']) ? (string) $data['created_by'] : null,
            ]);

            foreach ($calculatedItems as $item) {
                SalesQuoteItem::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'quote_id' => (string) $quote->id,
                    'product_id' => $item['product_id'] ?? null,
                    'description' => $item['description'] ?? 'Product item',
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'],
                    'discount_percentage' => $item['discount_percentage'],
                    'line_total' => $item['line_total'],
                    'cost_price' => DocumentTotalsCalculator::decimal($item['cost_price'] ?? '0'),
                    'margin_percentage' => DocumentTotalsCalculator::decimal($item['margin_percentage'] ?? '0'),
                    'uom' => $item['uom'] ?? 'EA',
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            return $quote;
        });

        $this->dispatchDomainEvent(new SalesQuoteCreatedEvent(
            quoteId: (string) $quote->id,
            quoteNumber: (string) $quote->quote_number,
            customerId: (string) $quote->contact_id,
            totalGross: (float) $quote->total_gross,
            currency: (string) $quote->currency,
            tenantId: $tenantId,
            companyId: $tenantId
        ));

        return $quote->load('items');
    }

    /**
     * Puts a draft quote up for internal approval. When the tenant has an active approval workflow (the high value one
     * above the threshold, when defined), an instance is started and the decision is taken there; otherwise the quote
     * waits for an owner, administrator or sales manager to approve or reject it directly.
     */
    public function submitForApproval(SalesQuote $quote, string $userId): SalesQuote
    {
        $submitted = DB::transaction(function () use ($quote, $userId): SalesQuote {
            $locked = SalesQuote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            QuoteStatus::assertTransition($locked->status, QuoteStatus::PendingApproval);

            $locked->update(['status' => QuoteStatus::PendingApproval->value]);

            $workflowCode = $this->approvalWorkflowFor($locked);
            if ($workflowCode !== null && $this->workflowEngine !== null) {
                $instance = $this->workflowEngine->start(new StartWorkflowDTO(
                    workflowCode: $workflowCode,
                    approvable: $locked,
                    contextSnapshot: [
                        'quote_number' => (string) $locked->quote_number,
                        'customer_id' => (string) $locked->contact_id,
                        'total_gross' => (float) $locked->total_gross,
                        'currency' => (string) $locked->currency,
                    ],
                    tenantId: (string) $locked->tenant_id,
                    companyId: (string) $locked->tenant_id,
                    initiatorUserId: Str::isUuid($userId) ? $userId : null
                ));

                $locked->update(['workflow_instance_id' => (string) $instance->id]);
                $this->audit('SALES_QUOTE_WORKFLOW_TRIGGERED', $locked, ['workflow_instance_id' => (string) $instance->id, 'workflow_code' => $workflowCode]);
            }

            return $locked;
        });

        $quote->setRawAttributes($submitted->getAttributes(), true);

        $this->dispatchDomainEvent(new SalesQuoteSubmittedEvent(
            quoteId: (string) $quote->id,
            quoteNumber: (string) $quote->quote_number,
            customerId: (string) $quote->contact_id,
            totalGross: (float) $quote->total_gross,
            currency: (string) $quote->currency,
            userId: $userId,
            tenantId: (string) $quote->tenant_id,
            companyId: (string) $quote->tenant_id
        ));

        return $quote;
    }

    /**
     * Approves a quote waiting for approval: called when the approval workflow completes, or by a manager when there is
     * no workflow (see approveWithoutWorkflow).
     */
    public function approveQuote(SalesQuote $quote, string $userId): SalesQuote
    {
        QuoteStatus::assertTransition($quote->status, QuoteStatus::Approved);

        $quote->update([
            'status' => QuoteStatus::Approved->value,
            'approved_by' => Str::isUuid($userId) ? $userId : null,
            'approved_at' => now(),
        ]);

        $this->dispatchDomainEvent(new SalesQuoteApprovedEvent(
            quoteId: (string) $quote->id,
            approvedBy: $userId,
            tenantId: (string) $quote->tenant_id,
            companyId: (string) $quote->tenant_id
        ));

        return $quote;
    }

    /**
     * Rejects a quote waiting for approval, keeping the reason in the notes of the quote.
     */
    public function rejectQuote(SalesQuote $quote, string $userId, ?string $reason = null): SalesQuote
    {
        QuoteStatus::assertTransition($quote->status, QuoteStatus::Rejected);

        $quote->update(array_filter([
            'status' => QuoteStatus::Rejected->value,
            'notes' => $reason !== null && $reason !== '' ? trim(($quote->notes ?? '')."\nRejection reason: ".$reason) : null,
        ], static fn (mixed $value): bool => $value !== null));

        return $quote;
    }

    /**
     * Whether the decision on the quote belongs to a running approval workflow instance.
     */
    public function hasActiveApprovalWorkflow(SalesQuote $quote): bool
    {
        if ($quote->workflow_instance_id === null || ! class_exists(WorkflowInstance::class)) {
            return false;
        }

        return WorkflowInstance::withoutGlobalScopes()
            ->whereKey($quote->workflow_instance_id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->exists();
    }

    /**
     * Direct approval by a manager, allowed only while no approval workflow instance is running for the quote. The
     * caller checks the role; the decision is written to the audit ledger and the log.
     *
     * @throws BusinessRuleException when a running workflow instance owns the decision
     */
    public function approveWithoutWorkflow(SalesQuote $quote, string $userId): SalesQuote
    {
        return $this->decideWithoutWorkflow($quote, $userId, true, null);
    }

    /**
     * Direct rejection by a manager, allowed only while no approval workflow instance is running for the quote.
     *
     * @throws BusinessRuleException when a running workflow instance owns the decision
     */
    public function rejectWithoutWorkflow(SalesQuote $quote, string $userId, ?string $reason = null): SalesQuote
    {
        return $this->decideWithoutWorkflow($quote, $userId, false, $reason);
    }

    public function acceptQuote(SalesQuote $quote): SalesQuote
    {
        QuoteStatus::assertTransition($quote->status, QuoteStatus::Accepted);
        app(QuoteLifecycleService::class)->assertWithinValidity($quote);

        $quote->update([
            'status' => QuoteStatus::Accepted->value,
            'signed_at' => $quote->signed_at ?? now(),
        ]);

        $this->dispatchDomainEvent(new SalesQuoteAcceptedEvent(
            quoteId: (string) $quote->id,
            salesOrderId: null,
            tenantId: (string) $quote->tenant_id,
            companyId: (string) $quote->tenant_id,
            quoteNumber: (string) ($quote->quote_number ?? $quote->number ?? ''),
            customerId: (string) ($quote->contact_id ?? $quote->customer_id ?? ''),
            totalAmount: (float) ($quote->total_amount ?? $quote->total_gross ?? 0.0),
            salesQuoteId: (string) $quote->id
        ));

        return $quote;
    }

    /**
     * Turns the quote into a sales order, once. The quote ends accepted, with the link to the order.
     *
     * @throws BusinessRuleException when the quote was already converted
     */
    public function convertToOrder(SalesQuote $quote, string $userId): SalesOrder
    {
        return DB::transaction(function () use ($quote, $userId): SalesOrder {
            $locked = SalesQuote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            QuoteStatus::assertTransition($locked->status, QuoteStatus::Accepted);
            app(QuoteLifecycleService::class)->assertWithinValidity($locked);

            if ($locked->converted_to_order_id !== null || SalesOrder::query()->where('quote_id', $locked->id)->exists()) {
                throw new BusinessRuleException(__('sales.quote_already_converted'));
            }

            $locked->load('items');
            $tenantId = (string) $locked->tenant_id;
            $today = CarbonImmutable::parse($this->today($tenantId));

            $orderData = [
                'tenant_id' => $tenantId,
                'contact_id' => (string) $locked->contact_id,
                'quote_id' => (string) $locked->id,
                'date' => $today->toDateString(),
                'due_date' => $today->addDays(30)->toDateString(),
                'currency' => $locked->currency ?? 'EUR',
                'price_list_id' => $locked->price_list_id,
                'discount_policy_id' => $locked->discount_policy_id,
                'payment_terms' => $locked->payment_terms,
                'shipping_method' => $locked->shipping_terms,
                'notes' => 'Converted from Quote '.$locked->quote_number,
                'created_by' => $userId,
            ];

            $orderItems = [];
            foreach ($locked->items as $item) {
                $orderItems[] = [
                    'product_id' => $item->product_id,
                    'description' => (string) $item->description,
                    'quantity' => DocumentTotalsCalculator::decimal($item->quantity),
                    'unit_price' => DocumentTotalsCalculator::decimal($item->unit_price),
                    'tax_rate' => DocumentTotalsCalculator::decimal($item->tax_rate),
                    'discount_percentage' => DocumentTotalsCalculator::decimal($item->discount_percentage),
                    'cost_price' => DocumentTotalsCalculator::decimal($item->cost_price),
                    'uom' => (string) $item->uom,
                    'notes' => $item->notes,
                ];
            }

            $order = app(SalesOrderServiceInterface::class)->createOrder($orderData, $orderItems);

            $locked->update([
                'converted_to_order_id' => (string) $order->id,
                'status' => QuoteStatus::Accepted->value,
            ]);
            $quote->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }

    private function decideWithoutWorkflow(SalesQuote $quote, string $userId, bool $approve, ?string $reason): SalesQuote
    {
        return DB::transaction(function () use ($quote, $userId, $approve, $reason): SalesQuote {
            $locked = SalesQuote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($this->hasActiveApprovalWorkflow($locked)) {
                throw new BusinessRuleException(__('sales.quote_approval_in_workflow'));
            }

            if ($locked->status !== QuoteStatus::PendingApproval->value) {
                throw ValidationException::withMessages(['status' => __('sales.quote_not_pending_approval')]);
            }

            $before = $locked->only(['status', 'approved_by', 'approved_at']);
            $decided = $approve ? $this->approveQuote($locked, $userId) : $this->rejectQuote($locked, $userId, $reason);
            $action = $approve ? 'SALES_QUOTE_APPROVED_WITHOUT_WORKFLOW' : 'SALES_QUOTE_REJECTED_WITHOUT_WORKFLOW';

            $this->audit($action, $decided, ['decided_by' => $userId, 'reason' => $reason], $before);
            Log::info('Sales quote decided without an approval workflow.', [
                'quote_id' => (string) $decided->id,
                'tenant_id' => (string) $decided->tenant_id,
                'decision' => $approve ? 'approved' : 'rejected',
                'user_id' => $userId,
            ]);

            $quote->setRawAttributes($decided->getAttributes(), true);

            return $quote;
        });
    }

    /**
     * Code of the active approval workflow for the quote, or null when the tenant has none.
     */
    private function approvalWorkflowFor(SalesQuote $quote): ?string
    {
        if (! class_exists(WorkflowDefinition::class)) {
            return null;
        }

        $codes = (float) $quote->total_gross > self::HighValueThreshold
            ? [self::HighValueApprovalWorkflow, self::ApprovalWorkflow]
            : [self::ApprovalWorkflow];

        $active = WorkflowDefinition::withoutGlobalScopes()
            ->where('tenant_id', (string) $quote->tenant_id)
            ->whereIn('code', $codes)
            ->where('is_active', true)
            ->pluck('code')
            ->all();

        foreach ($codes as $code) {
            if (in_array($code, $active, true)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>|null  $before
     */
    private function audit(string $action, SalesQuote $quote, array $after, ?array $before = null): void
    {
        $this->auditLedger?->record(
            action: $action,
            model: $quote,
            payloadBefore: $before,
            payloadAfter: array_merge(['quote_id' => (string) $quote->id, 'quote_number' => (string) $quote->quote_number, 'status' => (string) $quote->status], $after),
            moduleCode: 'MODULE_05_INVOICING'
        );
    }

    private function today(string $tenantId): string
    {
        return ($this->clock ?? app(TenantClock::class))->today(Tenant::query()->find($tenantId))->toDateString();
    }

    private function dispatchDomainEvent(DomainEventInterface $event): void
    {
        $this->eventDispatcher->publish($event);
        $this->eventDispatcher->dispatch($event);
    }
}
