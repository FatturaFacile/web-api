<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\SubscriptionBilling\Contracts\RecurringBillingServiceInterface;
use Modules\SubscriptionBilling\Contracts\SubscriptionLifecycleServiceInterface;
use Modules\SubscriptionBilling\DTOs\CancelSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\CreateSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\DowngradeSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\UpgradeSubscriptionDTO;
use Modules\SubscriptionBilling\Http\Requests\CancelSubscriptionRequest;
use Modules\SubscriptionBilling\Http\Requests\ChangePlanRequest;
use Modules\SubscriptionBilling\Http\Requests\ChangeQuantityRequest;
use Modules\SubscriptionBilling\Http\Requests\CollectionFailureRequest;
use Modules\SubscriptionBilling\Http\Requests\CreateSubscriptionRequest;
use Modules\SubscriptionBilling\Http\Requests\PauseSubscriptionRequest;
use Modules\SubscriptionBilling\Http\Resources\DunningRecordResource;
use Modules\SubscriptionBilling\Http\Resources\InvoiceScheduleResource;
use Modules\SubscriptionBilling\Http\Resources\SubscriptionResource;
use Modules\SubscriptionBilling\Models\DunningRecord;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;
use Modules\SubscriptionBilling\Services\DunningService;
use OpenApi\Attributes as OA;

/**
 * Subscription contracts of the active company: lifecycle, prorated changes, cancellations, billing, invoices and
 * dunning. Every query runs in the tenant scope; writes need the write access of the subscriptions module.
 */
final class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionLifecycleServiceInterface $lifecycle,
        private readonly RecurringBillingServiceInterface $billing,
        private readonly DunningService $dunning,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Paginated contracts, filtered by status and customer.
     */
    #[OA\Get(path: '/api/v1/sub/contracts', operationId: 'listSubContracts', summary: 'List subscription contracts', description: 'Paginated contracts of the active company, latest first.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Contracts'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'customer_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Matches the contract number, the label, the plan name or the customer name', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingPage'),
    ], responses: [
        new OA\Response(response: 200, description: 'Contracts', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SubscriptionBillingSubscriptionResource')), new OA\Property(property: 'meta', ref: '#/components/schemas/SubscriptionBillingPaginationMeta')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
    ])]
    public function index(Request $request): JsonResponse
    {
        $subscriptions = Subscription::query()
            ->with(['plan', 'customer'])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.trim((string) $request->query('search')).'%';
                $query->where(fn ($inner) => $inner
                    ->whereLike('subscription_number', $term)
                    ->orWhereLike('name', $term)
                    ->orWhereHas('plan', fn ($plan) => $plan->whereLike('name', $term))
                    ->orWhereHas('customer', fn ($customer) => $customer
                        ->whereLike('company_name', $term)
                        ->orWhereLike('first_name', $term)
                        ->orWhereLike('last_name', $term)));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->query('status')))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', (string) $request->query('customer_id')))
            ->orderByDesc('created_at')
            ->gridPaginate(RequestedPerPage::resolve(25));

        return response()->json([
            'data' => SubscriptionResource::collection($subscriptions->getCollection())->resolve(),
            'meta' => ['current_page' => $subscriptions->currentPage(), 'last_page' => $subscriptions->lastPage(), 'total' => $subscriptions->total()],
        ]);
    }

    /**
     * Creates a contract (trialing when the plan or the request gives trial days).
     */
    #[OA\Post(path: '/api/v1/sub/contracts', operationId: 'createSubContract', summary: 'Create subscription contract', description: 'Creates a contract of a customer of the company on an active plan: trial, billing alignment, term, renewal and notice rules.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Contracts'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingCreateSubscriptionRequest')), responses: [
        new OA\Response(ref: '#/components/responses/SubscriptionBillingContract', response: 201),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function store(CreateSubscriptionRequest $request): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $data = $request->validated();

        $subscription = $this->lifecycle->createSubscription(new CreateSubscriptionDTO(
            tenantId: $tenantId,
            companyId: $tenantId,
            customerId: $data['customer_id'],
            planId: $data['plan_id'],
            startDate: $data['start_date'],
            contractTermMonths: (int) ($data['contract_term_months'] ?? 12),
            autoRenew: (bool) ($data['auto_renew'] ?? true),
            billingDayOfMonth: (int) ($data['billing_day_of_month'] ?? CarbonImmutable::parse($data['start_date'])->day),
            paymentTermsDays: (int) ($data['payment_terms_days'] ?? 30),
            paymentMethod: $data['payment_method'] ?? 'sepa_direct_debit',
            bankAccountId: $data['bank_account_id'] ?? null,
            items: $data['items'] ?? [],
            metadata: $data['metadata'] ?? [],
            name: $data['name'] ?? null,
            quantity: (string) ($data['quantity'] ?? '1'),
            trialDays: isset($data['trial_days']) ? (int) $data['trial_days'] : null,
            billingAlignment: $data['billing_alignment'] ?? null,
            renewalNoticeDays: (int) ($data['renewal_notice_days'] ?? 30),
            cancellationNoticeDays: (int) ($data['cancellation_notice_days'] ?? 30),
            renewalPriceIncreasePercent: isset($data['renewal_price_increase_percent']) ? (string) $data['renewal_price_increase_percent'] : null,
            notes: $data['notes'] ?? null,
        ), $this->actor($request));

        return $this->contract($subscription, 'Subscription created successfully', 201);
    }

    /**
     * A contract with its plan, items and versions.
     */
    #[OA\Get(path: '/api/v1/sub/contracts/{id}', operationId: 'getSubContract', summary: 'Get subscription contract', description: 'A contract of the active company with its plan, items and versions.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Contracts'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingContractId')], responses: [
        new OA\Response(ref: '#/components/responses/SubscriptionBillingContract', response: 200),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        return $this->contract($this->find($id), 'Subscription retrieved');
    }

    /**
     * Activates a contract waiting for approval.
     */
    #[OA\Post(path: '/api/v1/sub/contracts/{id}/activate', operationId: 'activateSubContract', summary: 'Activate subscription contract', description: 'Activates a pending or draft contract.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Contracts'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingContractId')], responses: [
        new OA\Response(ref: '#/components/responses/SubscriptionBillingContract', response: 200),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function activate(Request $request, string $id): JsonResponse
    {
        return $this->contract($this->lifecycle->activateSubscription($this->find($id)->id, $this->actor($request)), 'Subscription activated successfully');
    }

    /**
     * Suspends a contract until it is reactivated.
     */
    #[OA\Post(path: '/api/v1/sub/contracts/{id}/suspend', operationId: 'suspendSubContract', summary: 'Suspend subscription contract', description: 'Stops the billing of a live contract until it is reactivated.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Contracts'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingContractId')], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingPauseRequest')), responses: [
        new OA\Response(ref: '#/components/responses/SubscriptionBillingContract', response: 200),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function suspend(PauseSubscriptionRequest $request, string $id): JsonResponse
    {
        $subscription = $this->lifecycle->suspendSubscription($this->find($id)->id, (string) ($request->validated('reason') ?? 'Administrative suspension'), $this->actor($request));

        return $this->contract($subscription, 'Subscription suspended successfully');
    }

    /**
     * Pauses a contract, optionally until a date.
     */
    #[OA\Post(path: '/api/v1/sub/contracts/{id}/pause', operationId: 'pauseSubContract', summary: 'Pause subscription contract', description: 'Pauses a live contract; it resumes by itself on resume_on or when reactivated. The periods that end during the pause are not billed.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Contracts'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingContractId')], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingPauseRequest')), responses: [
        new OA\Response(ref: '#/components/responses/SubscriptionBillingContract', response: 200),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function pause(PauseSubscriptionRequest $request, string $id): JsonResponse
    {
        $subscription = $this->lifecycle->pauseSubscription($this->find($id)->id, (string) ($request->validated('reason') ?? 'Paused'), $request->validated('resume_on'), $this->actor($request));

        return $this->contract($subscription, 'Subscription paused successfully');
    }

    /**
     * Resumes a paused or suspended contract.
     */
    #[OA\Post(path: '/api/v1/sub/contracts/{id}/reactivate', operationId: 'reactivateSubContract', summary: 'Reactivate subscription contract', description: 'Resumes a paused or suspended contract.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Contracts'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingContractId')], responses: [
        new OA\Response(ref: '#/components/responses/SubscriptionBillingContract', response: 200),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function reactivate(Request $request, string $id): JsonResponse
    {
        return $this->contract($this->lifecycle->reactivateSubscription($this->find($id)->id, $this->actor($request)), 'Subscription reactivated successfully');
    }

    /**
     * Prorated move to a higher plan.
     */
    #[OA\Post(path: '/api/v1/sub/contracts/{id}/upgrade', operationId: 'upgradeSubContract', summary: 'Upgrade subscription contract', description: 'Moves to a plan costing more per period; the rest of the billed period is invoiced pro rata.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Contracts'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingContractId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingChangePlanRequest')), responses: [
        new OA\Response(ref: '#/components/responses/SubscriptionBillingContract', response: 200),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function upgrade(ChangePlanRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();
        $subscription = $this->lifecycle->upgradeSubscription(new UpgradeSubscriptionDTO($this->find($id)->id, $data['new_plan_id'], $data['effective_date'] ?? null, $data['change_reason'] ?? null, $this->actor($request), isset($data['quantity']) ? (string) $data['quantity'] : null));

        return $this->contract($subscription, 'Subscription upgraded successfully');
    }

    /**
     * Prorated move to a lower plan.
     */
    #[OA\Post(path: '/api/v1/sub/contracts/{id}/downgrade', operationId: 'downgradeSubContract', summary: 'Downgrade subscription contract', description: 'Moves to a plan costing less per period; the unused part of the billed period is credited with a credit note.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Contracts'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingContractId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingChangePlanRequest')), responses: [
        new OA\Response(ref: '#/components/responses/SubscriptionBillingContract', response: 200),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function downgrade(ChangePlanRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();
        $subscription = $this->lifecycle->downgradeSubscription(new DowngradeSubscriptionDTO($this->find($id)->id, $data['new_plan_id'], $data['effective_date'] ?? null, $data['change_reason'] ?? null, $this->actor($request), isset($data['quantity']) ? (string) $data['quantity'] : null));

        return $this->contract($subscription, 'Subscription downgraded successfully');
    }

    /**
     * Prorated change of the quantity (seats).
     */
    #[OA\Post(path: '/api/v1/sub/contracts/{id}/quantity', operationId: 'changeSubContractQuantity', summary: 'Change subscription quantity', description: 'Changes the seats of the plan item; an increase is invoiced, a decrease credited, pro rata for the rest of the billed period.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Contracts'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingContractId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingChangeQuantityRequest')), responses: [
        new OA\Response(ref: '#/components/responses/SubscriptionBillingContract', response: 200),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function quantity(ChangeQuantityRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();
        $subscription = $this->lifecycle->changeQuantity($this->find($id)->id, (string) $data['quantity'], $data['effective_date'] ?? null, $data['reason'] ?? null, $this->actor($request));

        return $this->contract($subscription, 'Subscription quantity changed successfully');
    }

    /**
     * Proration a plan or quantity change would produce, without applying it.
     */
    #[OA\Get(path: '/api/v1/sub/contracts/{id}/proration-preview', operationId: 'previewSubContractProration', summary: 'Preview proration', description: 'What a plan or quantity change would invoice (positive) or credit (negative) for the rest of the billed period.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Contracts'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingContractId'),
        new OA\Parameter(name: 'new_plan_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'quantity', in: 'query', required: false, schema: new OA\Schema(type: 'number')),
        new OA\Parameter(name: 'effective_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Proration', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingProrationPreview')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function prorationPreview(Request $request, string $id): JsonResponse
    {
        $subscription = $this->find($id);
        $tenantId = (string) $this->tenantContext->getTenantId();
        $data = $request->validate([
            'new_plan_id' => ['nullable', 'uuid', Rule::exists('sub_plans', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'effective_date' => ['nullable', 'date'],
        ]);
        $plan = isset($data['new_plan_id']) ? SubscriptionPlan::query()->findOrFail($data['new_plan_id']) : $subscription->plan;
        $effective = isset($data['effective_date']) ? CarbonImmutable::parse($data['effective_date']) : CarbonImmutable::today();

        return response()->json(['data' => $this->lifecycle->calculateProration($subscription, $plan, $effective, isset($data['quantity']) ? (string) $data['quantity'] : null)]);
    }

    /**
     * Cancels immediately or at the end of the term.
     */
    #[OA\Post(path: '/api/v1/sub/contracts/{id}/cancel', operationId: 'cancelSubContract', summary: 'Cancel subscription contract', description: 'Immediate cancellation (with a credit note for the unused days unless refund is false) or at the end of the term (after one more term when the notice period of an automatically renewing term has passed).', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Contracts'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingContractId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingCancelSubscriptionRequest')), responses: [
        new OA\Response(ref: '#/components/responses/SubscriptionBillingContract', response: 200),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function cancel(CancelSubscriptionRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();
        $subscription = $this->lifecycle->cancelSubscription(new CancelSubscriptionDTO(
            subscriptionId: $this->find($id)->id,
            reason: $data['reason'],
            cancellationDate: $data['cancellation_date'] ?? null,
            actorUserId: $this->actor($request),
            reasonCode: $data['reason_code'] ?? 'other',
            cancellationType: $data['cancellation_type'] ?? 'immediate',
            refund: (bool) ($data['refund'] ?? true),
        ));

        return $this->contract($subscription, 'Subscription cancelled successfully');
    }

    /**
     * Bills the periods of the contract due today.
     */
    #[OA\Post(path: '/api/v1/sub/contracts/{id}/bill', operationId: 'billSubContract', summary: 'Bill subscription contract', description: 'Issues one invoice per due period through the invoicing services; running it again creates nothing.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Contracts'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingContractId')], responses: [
        new OA\Response(response: 200, description: 'Invoices created', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SubscriptionBillingInvoiceScheduleResource')), new OA\Property(property: 'error', type: 'string', nullable: true)])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
    ])]
    public function bill(Request $request, string $id): JsonResponse
    {
        $outcome = $this->billing->billSubscription($this->find($id)->id, CarbonImmutable::today(), CarbonImmutable::today(), null, $this->actor($request));

        return response()->json([
            'message' => count($outcome['schedules']).' invoice(s) created',
            'data' => InvoiceScheduleResource::collection(collect($outcome['schedules']))->resolve(),
            'error' => $outcome['error'],
        ]);
    }

    /**
     * Billed periods, proration invoices and credit notes of the contract.
     */
    #[OA\Get(path: '/api/v1/sub/contracts/{id}/invoices', operationId: 'listSubContractInvoices', summary: 'List subscription invoices', description: 'Billed periods with their invoices, proration invoices, credit notes and failed attempts, oldest first.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Contracts'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingContractId')], responses: [
        new OA\Response(response: 200, description: 'Invoices', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SubscriptionBillingInvoiceScheduleResource'))])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
    ])]
    public function invoices(string $id): JsonResponse
    {
        $schedules = InvoiceSchedule::query()->with('invoice')->where('subscription_id', $this->find($id)->id)->orderBy('service_period_start')->orderBy('created_at')->get();

        return response()->json(['data' => InvoiceScheduleResource::collection($schedules)->resolve()]);
    }

    /**
     * Records a failed collection of an invoice of the contract.
     */
    #[OA\Post(path: '/api/v1/sub/contracts/{id}/collection-failures', operationId: 'recordSubContractCollectionFailure', summary: 'Record failed collection', description: 'A returned direct debit or a declined card: opens (or updates) the dunning case, schedules the next retry and marks the contract past due.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Dunning'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingContractId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingCollectionFailureRequest')), responses: [
        new OA\Response(response: 201, description: 'Dunning case', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingDunningRecordResource')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function collectionFailure(CollectionFailureRequest $request, string $id): JsonResponse
    {
        $record = $this->dunning->recordCollectionFailure($this->find($id)->id, (string) $request->validated('invoice_id'), $request->validated('reason'), $this->actor($request));

        return response()->json(['message' => 'Collection failure recorded', 'data' => (new DunningRecordResource($record))->resolve()], 201);
    }

    /**
     * Dunning cases of the contract.
     */
    #[OA\Get(path: '/api/v1/sub/contracts/{id}/dunning', operationId: 'listSubContractDunning', summary: 'List dunning cases', description: 'Open and closed dunning cases of the contract, latest first.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Dunning'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingContractId')], responses: [
        new OA\Response(response: 200, description: 'Dunning cases', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SubscriptionBillingDunningRecordResource'))])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
    ])]
    public function dunning(string $id): JsonResponse
    {
        $records = DunningRecord::query()->where('subscription_id', $this->find($id)->id)->orderByDesc('created_at')->get();

        return response()->json(['data' => DunningRecordResource::collection($records)->resolve()]);
    }

    private function find(string $id): Subscription
    {
        return Subscription::query()->with(['plan.pricingTiers', 'items'])->findOrFail($id);
    }

    private function contract(Subscription $subscription, string $message, int $status = 200): JsonResponse
    {
        $subscription = Subscription::query()->with(['plan.pricingTiers', 'plan.features', 'items', 'versions'])->findOrFail($subscription->id);

        return response()->json(['message' => $message, 'data' => (new SubscriptionResource($subscription))->resolve()], $status);
    }

    private function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }
}
