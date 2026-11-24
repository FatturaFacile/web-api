<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Http\Requests\ChangeRecurringJournalStatusRequest;
use Modules\GeneralLedger\Http\Requests\LedgerAsOfRequest;
use Modules\GeneralLedger\Http\Requests\StoreRecurringJournalRequest;
use Modules\GeneralLedger\Http\Resources\JournalEntryResource;
use Modules\GeneralLedger\Http\Resources\RecurringJournalResource;
use Modules\GeneralLedger\Models\RecurringJournal;
use Modules\GeneralLedger\Services\RecurringJournalService;
use Modules\GeneralLedger\Services\ScheduledReversalService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recurring journals and the run of the scheduled ledger work (recurring runs and automatic reversals).
 */
final class RecurringJournalController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RecurringJournalService $recurring,
        private readonly ScheduledReversalService $reversals,
        private readonly TenantContext $tenantContext,
        private readonly TenantClock $clock,
    ) {}

    /**
     * The recurring journals of the tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/recurring-journals',
        operationId: 'listGeneralLedgerRecurringJournals',
        description: 'Recurring journal templates of the tenant, by next due date, optionally only one status.',
        summary: 'List recurring journals',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Recurring Journals'],
        parameters: [new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'paused', 'ended']))],
        responses: [
            new OA\Response(response: 200, description: 'Recurring journals', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerRecurringJournalResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $templates = RecurringJournal::query()
            ->when($request->query('status'), static fn ($query, $status) => $query->where('status', $status))
            ->orderBy('next_run_date')
            ->gridPaginate(min(1000, max(1, (int) $request->query('per_page', 1000))));

        return $this->successResponse(
            data: RecurringJournalResource::collection($templates),
            meta: ['current_page' => $templates->currentPage(), 'last_page' => $templates->lastPage(), 'per_page' => $templates->perPage(), 'total' => $templates->total()],
        );
    }

    /**
     * Saves a recurring journal template.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/recurring-journals',
        operationId: 'createGeneralLedgerRecurringJournal',
        description: 'Saves a recurring journal (rent, leasing, fixed monthly charges): balanced lines posted at every due date from `start_date` with the given frequency, until `end_date`. Each run is a manual journal of type recurring in the fiscal period of its date, subject to the four-eyes policy; with `reverse_next_day` every run is reversed automatically on the next day. Lines follow the manual posting rules of their accounts.',
        summary: 'Create recurring journal',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Recurring Journals'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreRecurringJournalRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Recurring journal saved', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerRecurringJournalResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error, unbalanced lines or account closed to manual postings', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreRecurringJournalRequest $request): JsonResponse
    {
        $template = $this->recurring->create((string) $this->tenantContext->getTenantId(), $request->validated(), (string) $request->user()->id);

        return $this->successResponse(new RecurringJournalResource($template), 'Recurring journal saved', Response::HTTP_CREATED);
    }

    /**
     * A recurring journal with its runs.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/recurring-journals/{id}',
        operationId: 'getGeneralLedgerRecurringJournal',
        description: 'A recurring journal template with the runs already posted and their journal entries.',
        summary: 'Get recurring journal',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Recurring Journals'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Recurring journal', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerRecurringJournalResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new RecurringJournalResource(RecurringJournal::with('runs')->findOrFail($id)));
    }

    /**
     * Pauses, resumes or ends a template.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/recurring-journals/{id}/status',
        operationId: 'changeGeneralLedgerRecurringJournalStatus',
        description: 'Pauses (no run until resumed), resumes or ends (never run again) a recurring journal. Refused with 409 for an ended template.',
        summary: 'Change recurring journal status',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Recurring Journals'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['status'], properties: [new OA\Property(property: 'status', type: 'string', enum: ['active', 'paused', 'ended'], example: 'paused')])),
        responses: [
            new OA\Response(response: 200, description: 'Status changed', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerRecurringJournalResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Template already ended', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Unknown status', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function changeStatus(ChangeRecurringJournalStatusRequest $request, string $id): JsonResponse
    {
        $template = $this->recurring->changeStatus(RecurringJournal::findOrFail($id), (string) $request->validated('status'));

        return $this->successResponse(new RecurringJournalResource($template), 'Recurring journal status changed');
    }

    /**
     * Posts the next due run of a template now.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/recurring-journals/{id}/run',
        operationId: 'runGeneralLedgerRecurringJournal',
        description: 'Posts the next due run of a recurring journal, when it is due on or before `as_of` (default: today in the tenant time zone). A run already posted for its date is returned again, never posted twice. Refused with 409 when the template is not active or not yet due, with 422 when the fiscal period of the due date is missing or closed.',
        summary: 'Run recurring journal',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Recurring Journals'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'as_of', type: 'string', format: 'date', nullable: true, example: '2027-03-31')])),
        responses: [
            new OA\Response(response: 200, description: 'Run posted (or waiting for approval)', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerJournalEntryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Template not active or not yet due', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Fiscal period missing or closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function run(LedgerAsOfRequest $request, string $id): JsonResponse
    {
        $entry = $this->recurring->runNext(RecurringJournal::findOrFail($id), $this->asOf($request), (string) $request->user()->id);

        return $this->successResponse(new JournalEntryResource($entry->load('lines')), 'Recurring journal run posted');
    }

    /**
     * Runs the scheduled ledger work of the tenant up to a day.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/scheduled-journals/run',
        operationId: 'runGeneralLedgerScheduledJournals',
        description: 'Posts every recurring run due on or before `as_of` (default: today in the tenant time zone) and every automatic reversal (accruals, deferrals, exchange revaluations, journals with auto_reverse_on) due by then. The same work runs every night through the general-ledger:run-scheduled command. Items that cannot be posted (closed period, inactive account) are listed under `failed` and the others go on.',
        summary: 'Run scheduled journals',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Recurring Journals'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'as_of', type: 'string', format: 'date', nullable: true, example: '2027-04-01')])),
        responses: [
            new OA\Response(response: 200, description: 'Scheduled work done', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerScheduledRunResult')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid date', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function runScheduled(LedgerAsOfRequest $request): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $asOf = $this->asOf($request);
        $actorId = (string) $request->user()->id;

        return $this->successResponse([
            'as_of' => $asOf->toDateString(),
            'recurring' => $this->recurring->runDue($tenantId, $asOf, $actorId),
            'reversals' => $this->reversals->processDue($tenantId, $asOf, $actorId),
        ], 'Scheduled journals processed');
    }

    private function asOf(LedgerAsOfRequest $request): CarbonImmutable
    {
        $date = $request->validated('as_of');

        return $date !== null ? CarbonImmutable::parse((string) $date) : $this->clock->today();
    }
}
