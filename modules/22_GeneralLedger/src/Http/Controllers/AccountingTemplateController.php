<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Http\Requests\InstantiateAccountingTemplateRequest;
use Modules\GeneralLedger\Http\Requests\PreviewAccountingTemplateRequest;
use Modules\GeneralLedger\Http\Requests\StoreAccountingTemplateRequest;
use Modules\GeneralLedger\Http\Resources\AccountingTemplateResource;
use Modules\GeneralLedger\Http\Resources\JournalEntryResource;
use Modules\GeneralLedger\Models\AccountingTemplate;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\AccountingTemplateService;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use OpenApi\Attributes as OA;

/**
 * Parametric journal templates (causali contabili): the global system templates and the ones of the tenant, a
 * preview of the lines a template yields and its posting to the prima nota.
 */
final class AccountingTemplateController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AccountingTemplateService $templates,
        private readonly FiscalPeriodResolver $periods,
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Get(
        path: '/api/v1/general-ledger/accounting-templates',
        operationId: 'listGeneralLedgerAccountingTemplates',
        description: 'Lists the parametric journal templates (causali contabili) visible to the tenant: the global system templates and its own, optionally of one `category`, with the name in the request locale.',
        summary: 'List accounting templates',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Templates'],
        parameters: [new OA\Parameter(name: 'category', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Templates', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', JournalEntry::class);

        $query = AccountingTemplate::query()->where('is_active', true)->orderBy('code');

        if ($request->filled('category')) {
            $query->where('category', (string) $request->query('category'));
        }

        return $this->successResponse(AccountingTemplateResource::collection($query->get())->resolve());
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/accounting-templates/{id}',
        operationId: 'getGeneralLedgerAccountingTemplate',
        description: 'Returns one accounting template with its parameters and lines.',
        summary: 'Show an accounting template',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Templates'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Template', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $this->authorize('viewAny', JournalEntry::class);

        return $this->successResponse((new AccountingTemplateResource(AccountingTemplate::query()->findOrFail($id)))->resolve());
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/accounting-templates',
        operationId: 'createGeneralLedgerAccountingTemplate',
        description: 'Creates a template of the tenant. Names are required in it, de, fr and en. A line names its account as a `$parameter` placeholder or a literal account code, a side and an amount expression (amount, tax, gross).',
        summary: 'Create an accounting template',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Templates'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 422, description: 'Invalid template', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreAccountingTemplateRequest $request): JsonResponse
    {
        $this->authorize('create', JournalEntry::class);

        $template = AccountingTemplate::create(array_merge(
            ['category' => 'general', 'journal_type' => 'standard', 'ledger_type' => 'statutory', 'is_system' => false],
            $request->validated(),
            ['tenant_id' => $this->tenantContext->getTenantId()]
        ));

        return $this->successResponse((new AccountingTemplateResource($template))->resolve(), statusCode: 201);
    }

    #[OA\Put(
        path: '/api/v1/general-ledger/accounting-templates/{id}',
        operationId: 'updateGeneralLedgerAccountingTemplate',
        description: 'Updates a template of the tenant. A global system template cannot be changed (403).',
        summary: 'Update an accounting template',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Templates'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 403, description: 'System template', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function update(StoreAccountingTemplateRequest $request, string $id): JsonResponse
    {
        $this->authorize('create', JournalEntry::class);
        $template = $this->ownTemplate($id);
        $template->update($request->validated());

        return $this->successResponse((new AccountingTemplateResource($template->refresh()))->resolve());
    }

    #[OA\Delete(
        path: '/api/v1/general-ledger/accounting-templates/{id}',
        operationId: 'deleteGeneralLedgerAccountingTemplate',
        description: 'Deletes a template of the tenant; a global system template cannot be deleted (403). Journals already posted from it are not affected.',
        summary: 'Delete an accounting template',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Templates'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean')])),
            new OA\Response(response: 403, description: 'System template', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $this->authorize('create', JournalEntry::class);
        $this->ownTemplate($id)->delete();

        return $this->successResponse(null);
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/accounting-templates/{id}/preview',
        operationId: 'previewGeneralLedgerAccountingTemplate',
        description: 'Fills a template with the net amount, the tax rate and the accounts of its placeholders and returns the journal lines it yields with their totals and whether they balance. Nothing is written; use it to hydrate the prima nota form.',
        summary: 'Preview an accounting template',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Templates'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Preview', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 422, description: 'Missing parameter or unknown account', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function preview(PreviewAccountingTemplateRequest $request, string $id): JsonResponse
    {
        $this->authorize('create', JournalEntry::class);
        $template = AccountingTemplate::query()->findOrFail($id);

        return $this->successResponse($this->templates->preview($template, $this->tenantId(), $request->validated()));
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/accounting-templates/{id}/post',
        operationId: 'postGeneralLedgerAccountingTemplate',
        description: 'Posts a template as a journal entry of the ledger book of the template, in the open fiscal period of the posting date. `post_to_ledgers` also posts the same lines to the other books (01 statutory, 02 tax, 03 ifrs) as entries numbered after the first.',
        summary: 'Post an accounting template',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Templates'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 201, description: 'Journal entry created', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 422, description: 'Invalid inputs or no open period', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function post(InstantiateAccountingTemplateRequest $request, string $id): JsonResponse
    {
        $this->authorize('create', JournalEntry::class);
        $template = AccountingTemplate::query()->findOrFail($id);
        $input = $request->validated();
        $input['fiscal_period_id'] ??= (string) $this->periods->forDate($this->tenantId(), (string) $input['posting_date'])->id;

        $entry = $this->templates->instantiate($template, $this->tenantId(), $input, (string) $request->user()?->id);

        return $this->successResponse((new JournalEntryResource($entry))->resolve(), statusCode: 201);
    }

    private function ownTemplate(string $id): AccountingTemplate
    {
        $template = AccountingTemplate::query()->findOrFail($id);

        abort_if($template->tenant_id === null || $template->is_system, 403, __('general_ledger_errors.template.system_readonly'));

        return $template;
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }
}
