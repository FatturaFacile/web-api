<?php

declare(strict_types=1);

namespace Modules\Intrastat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Intrastat\Enums\DeclarationStatus;
use Modules\Intrastat\Enums\IntrastatFlow;
use Modules\Intrastat\Enums\SupplyKind;
use Modules\Intrastat\Http\Requests\DeclarationRequest;
use Modules\Intrastat\Http\Requests\GenerateDeclarationRequest;
use Modules\Intrastat\Http\Requests\SubmitDeclarationRequest;
use Modules\Intrastat\Http\Resources\DeclarationResource;
use Modules\Intrastat\Models\IntrastatDeclaration;
use Modules\Intrastat\Models\IntrastatDeclarationLine;
use Modules\Intrastat\Services\DeclarationGenerator;
use Modules\Intrastat\Services\DeclarationValidator;
use OpenApi\Attributes as OA;

/**
 * The INTRA lists of the active company: drafted from the invoices or by hand, validated, marked as submitted.
 */
final class DeclarationController extends Controller
{
    use ApiResponse;

    #[OA\Get(path: '/api/v1/intrastat/declarations', operationId: 'listIntrastatDeclarations', description: 'Return the INTRA lists of the company, most recent period first, without their rows. Optional filters by flow, year and status.', summary: 'List INTRA lists', security: [['bearerAuth' => []]], tags: ['Intrastat'], parameters: [
        new OA\Parameter(name: 'flow', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['sales', 'purchases'])),
        new OA\Parameter(name: 'year', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'validated', 'submitted'])),
    ], responses: [
        new OA\Response(response: 200, description: 'INTRA lists', content: new OA\JsonContent(ref: '#/components/schemas/IntrastatDeclarationCollection')),
        new OA\Response(ref: '#/components/responses/IntrastatUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/IntrastatForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', IntrastatDeclaration::class);

        $filtered = IntrastatDeclaration::query()
            ->when($request->filled('flow'), static fn ($query) => $query->where('flow', (string) $request->query('flow')))
            ->when($request->filled('year'), static fn ($query) => $query->where('year', (int) $request->query('year')))
            ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')));

        $declarations = (clone $filtered)
            ->orderByDesc('year')
            ->orderByDesc('period')
            ->orderByDesc('reference_number')
            ->gridPaginate(RequestedPerPage::resolve(25));

        return DeclarationResource::collection($declarations)->additional(['summary' => $this->summary($filtered)]);
    }

    /**
     * Totals of the whole filtered list (not just the page), shown by the KPI cards.
     *
     * @param  Builder<IntrastatDeclaration>  $filtered
     * @return array{sales_total_eur: int, purchases_total_eur: int, draft_count: int, submitted_count: int}
     */
    private function summary(Builder $filtered): array
    {
        $totalOf = static fn (IntrastatFlow $flow): int => (int) IntrastatDeclarationLine::query()
            ->whereIn('declaration_id', (clone $filtered)->where('flow', $flow->value)->select('intrastat_declarations.id'))
            ->sum(DB::raw("CASE WHEN section = 2 AND sign = '-' THEN -COALESCE(amount_eur, 0) ELSE COALESCE(amount_eur, 0) END"));

        return [
            'sales_total_eur' => $totalOf(IntrastatFlow::Sales),
            'purchases_total_eur' => $totalOf(IntrastatFlow::Purchases),
            'draft_count' => (clone $filtered)->where('status', DeclarationStatus::Draft->value)->count(),
            'submitted_count' => (clone $filtered)->where('status', DeclarationStatus::Submitted->value)->count(),
        ];
    }

    #[OA\Post(path: '/api/v1/intrastat/declarations', operationId: 'storeIntrastatDeclaration', description: 'Create an empty INTRA list, to be filled by hand (for example a list of corrections only). The VAT number of the obliged party defaults to the one of the company; the reference number to the next of the company.', summary: 'Create INTRA list', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IntrastatDeclarationRequest')), tags: ['Intrastat'], responses: [
        new OA\Response(response: 201, description: 'INTRA list created', content: new OA\JsonContent(ref: '#/components/schemas/IntrastatDeclarationResponse')),
        new OA\Response(ref: '#/components/responses/IntrastatUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/IntrastatForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/IntrastatUnprocessable', response: 422),
    ])]
    public function store(DeclarationRequest $request): JsonResponse
    {
        $this->authorize('create', IntrastatDeclaration::class);

        $declaration = new IntrastatDeclaration($this->frontespizio($request->validated()));
        $declaration->status = DeclarationStatus::Draft;
        $declaration->reference_number ??= DeclarationGenerator::nextReferenceNumber();
        $declaration->save();

        return (new DeclarationResource($declaration->load('lines')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/intrastat/declarations/generate', operationId: 'generateIntrastatDeclaration', description: 'Draft an INTRA list from the invoices of the period: issued invoices to customers identified for VAT in another member state (sales), received invoices of suppliers of another member state (purchases). Goods and services are told apart by the Intrastat profile of the product, the VAT nature N3.2 of a sales invoice, or the TD18/TD17 integration document of a received invoice; other lines are reported as unclassified unless unclassified_as says how to treat them. Missing data are left empty and reported by the validation, never filled with placeholders. The report lists skipped documents (credit notes, customers without VAT number) and warnings (foreign currency).', summary: 'Generate INTRA list', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IntrastatGenerateRequest')), tags: ['Intrastat'], responses: [
        new OA\Response(response: 201, description: 'Draft INTRA list with its rows and the generation report', content: new OA\JsonContent(ref: '#/components/schemas/IntrastatDeclarationResponse')),
        new OA\Response(ref: '#/components/responses/IntrastatUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/IntrastatForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/IntrastatUnprocessable', response: 422),
    ])]
    public function generate(GenerateDeclarationRequest $request, DeclarationGenerator $generator): JsonResponse
    {
        $this->authorize('create', IntrastatDeclaration::class);

        $validated = $request->validated();
        $declaration = $generator->generate(
            $this->frontespizio(collect($validated)->except(['defaults', 'unclassified_as'])->all()),
            $validated['defaults'] ?? [],
            isset($validated['unclassified_as']) ? SupplyKind::from($validated['unclassified_as']) : null,
        );

        return (new DeclarationResource($declaration))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/intrastat/declarations/{id}', operationId: 'showIntrastatDeclaration', description: 'Return an INTRA list with its rows and the totals of each section.', summary: 'Show INTRA list', security: [['bearerAuth' => []]], tags: ['Intrastat'], parameters: [new OA\Parameter(ref: '#/components/parameters/IntrastatId')], responses: [
        new OA\Response(response: 200, description: 'INTRA list', content: new OA\JsonContent(ref: '#/components/schemas/IntrastatDeclarationResponse')),
        new OA\Response(ref: '#/components/responses/IntrastatUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/IntrastatForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/IntrastatNotFound', response: 404),
    ])]
    public function show(string $id): DeclarationResource
    {
        $declaration = IntrastatDeclaration::query()->with('lines')->findOrFail($id);
        $this->authorize('view', $declaration);

        return new DeclarationResource($declaration);
    }

    #[OA\Put(path: '/api/v1/intrastat/declarations/{id}', operationId: 'updateIntrastatDeclaration', description: 'Change the frontespizio of a list that has not been submitted. The flow never changes. A validated list goes back to draft.', summary: 'Update INTRA list', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IntrastatDeclarationRequest')), tags: ['Intrastat'], parameters: [new OA\Parameter(ref: '#/components/parameters/IntrastatId')], responses: [
        new OA\Response(response: 200, description: 'INTRA list updated', content: new OA\JsonContent(ref: '#/components/schemas/IntrastatDeclarationResponse')),
        new OA\Response(ref: '#/components/responses/IntrastatUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/IntrastatForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/IntrastatNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/IntrastatUnprocessable', response: 422),
    ])]
    public function update(DeclarationRequest $request, string $id): DeclarationResource|JsonResponse
    {
        $declaration = IntrastatDeclaration::query()->findOrFail($id);
        $this->authorize('update', $declaration);

        if (! $declaration->status->isEditable()) {
            return $this->submittedProblem();
        }

        $declaration->fill($request->validated());
        $declaration->status = DeclarationStatus::Draft;
        $declaration->validated_at = null;
        $declaration->save();

        return new DeclarationResource($declaration->load('lines'));
    }

    #[OA\Delete(path: '/api/v1/intrastat/declarations/{id}', operationId: 'deleteIntrastatDeclaration', description: 'Delete a list that has not been submitted, with its rows.', summary: 'Delete INTRA list', security: [['bearerAuth' => []]], tags: ['Intrastat'], parameters: [new OA\Parameter(ref: '#/components/parameters/IntrastatId')], responses: [
        new OA\Response(response: 204, description: 'INTRA list deleted'),
        new OA\Response(ref: '#/components/responses/IntrastatUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/IntrastatForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/IntrastatNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/IntrastatUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response|JsonResponse
    {
        $declaration = IntrastatDeclaration::query()->findOrFail($id);
        $this->authorize('delete', $declaration);

        if (! $declaration->status->isEditable()) {
            return $this->submittedProblem();
        }

        $declaration->delete();

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/intrastat/declarations/{id}/validate', operationId: 'validateIntrastatDeclaration', description: 'Check the list against the instructions of the INTRA models (mandatory columns per section and periodicity, code lists, VAT code lengths of Tabella A). Without errors the list becomes validated and can be exported; otherwise every error is returned with its section, row and column, and the list stays a draft.', summary: 'Validate INTRA list', security: [['bearerAuth' => []]], tags: ['Intrastat'], parameters: [new OA\Parameter(ref: '#/components/parameters/IntrastatId')], responses: [
        new OA\Response(response: 200, description: 'The list is valid', content: new OA\JsonContent(ref: '#/components/schemas/IntrastatValidationResponse')),
        new OA\Response(ref: '#/components/responses/IntrastatUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/IntrastatForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/IntrastatNotFound', response: 404),
        new OA\Response(response: 422, description: 'The list has errors, or was already submitted', content: new OA\JsonContent(ref: '#/components/schemas/IntrastatValidationProblem')),
    ])]
    public function check(string $id, DeclarationValidator $validator): JsonResponse
    {
        $declaration = IntrastatDeclaration::query()->with('lines')->findOrFail($id);
        $this->authorize('update', $declaration);

        if (! $declaration->status->isEditable()) {
            return $this->submittedProblem();
        }

        $errors = $validator->validate($declaration);

        if ($errors !== []) {
            $declaration->forceFill(['status' => DeclarationStatus::Draft, 'validated_at' => null])->save();

            return $this->problemDetailsResponse('Invalid INTRA list', count($errors).' error(s) must be corrected before the list can be exported.', Response::HTTP_UNPROCESSABLE_ENTITY, invalidParams: $errors);
        }

        $declaration->forceFill(['status' => DeclarationStatus::Validated, 'validated_at' => now()])->save();

        return $this->successResponse((new DeclarationResource($declaration))->resolve(), 'The INTRA list is valid');
    }

    #[OA\Post(path: '/api/v1/intrastat/declarations/{id}/submit', operationId: 'submitIntrastatDeclaration', description: 'Record that a validated list was sent, with the protocol assigned by the receiving channel. A submitted list can no longer change: corrections go into sections 2 and 4 of a later list.', summary: 'Mark INTRA list as submitted', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IntrastatSubmitRequest')), tags: ['Intrastat'], parameters: [new OA\Parameter(ref: '#/components/parameters/IntrastatId')], responses: [
        new OA\Response(response: 200, description: 'INTRA list submitted', content: new OA\JsonContent(ref: '#/components/schemas/IntrastatDeclarationResponse')),
        new OA\Response(ref: '#/components/responses/IntrastatUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/IntrastatForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/IntrastatNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/IntrastatUnprocessable', response: 422),
    ])]
    public function submit(SubmitDeclarationRequest $request, string $id): DeclarationResource|JsonResponse
    {
        $declaration = IntrastatDeclaration::query()->findOrFail($id);
        $this->authorize('update', $declaration);

        if ($declaration->status !== DeclarationStatus::Validated) {
            return $this->problemDetailsResponse('INTRA list not validated', 'Only a validated list can be marked as submitted.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $declaration->forceFill([
            'status' => DeclarationStatus::Submitted,
            'protocol_number' => $request->validated('protocol_number'),
            'submitted_at' => $request->validated('submitted_at') ?? now(),
            'submitted_by' => $request->user()->id,
        ])->save();

        return new DeclarationResource($declaration->load('lines'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function frontespizio(array $attributes): array
    {
        $attributes['obligated_vat_number'] ??= $this->companyVatNumber();

        return $attributes;
    }

    /**
     * The Italian VAT number of the company, as the 11 digits the lists carry.
     */
    private function companyVatNumber(): ?string
    {
        $vatNumber = Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->value('vat_number');
        $digits = (string) preg_replace('/^IT/i', '', (string) preg_replace('/[\s.\-]/', '', (string) $vatNumber));

        return preg_match('/^\d{11}$/', $digits) === 1 ? $digits : null;
    }

    private function submittedProblem(): JsonResponse
    {
        return $this->problemDetailsResponse('INTRA list submitted', 'A submitted list cannot change: enter corrections in sections 2 and 4 of a later list.', Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
