<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\ForeignVatRefund\Domain\Enums\PeriodType;
use Modules\ForeignVatRefund\Http\Controllers\Concerns\InteractsWithForeignVat;
use Modules\ForeignVatRefund\Http\Requests\CreateClaimRequest;
use Modules\ForeignVatRefund\Http\Requests\DecideClaimRequest;
use Modules\ForeignVatRefund\Http\Requests\NoInputRequest;
use Modules\ForeignVatRefund\Http\Requests\PreviewClaimRequest;
use Modules\ForeignVatRefund\Http\Requests\ReconcileClaimRequest;
use Modules\ForeignVatRefund\Http\Requests\RequestInformationRequest;
use Modules\ForeignVatRefund\Http\Requests\SubmitClaimRequest;
use Modules\ForeignVatRefund\Http\Resources\ClaimResource;
use Modules\ForeignVatRefund\Models\VatRefundClaim;
use Modules\ForeignVatRefund\Services\ClaimService;
use Modules\ForeignVatRefund\Services\Export\ClaimExportService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Refund claims (dossiers): preview and creation from the eligible ledger lines, the lifecycle (ready, submitted, information requested,
 * decided, refunded with the accounting reconciliation) and the downloads of the official files.
 */
final class ClaimController extends Controller
{
    use ApiResponse;
    use InteractsWithForeignVat;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/foreign-vat-refund/claims', operationId: 'foreignVatRefundClaimsIndex', summary: 'Refund claims of the company, filterable by status, country and year', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Paged claims')])]
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', VatRefundClaim::class);

        $page = VatRefundClaim::query()->withCount('items')
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', (string) $status))
            ->when($request->query('country'), fn ($query, $country) => $query->where('target_country_code', strtoupper((string) $country)))
            ->when($request->query('fiscal_year'), fn ($query, $year) => $query->where('fiscal_year', (int) $year))
            ->orderByDesc('fiscal_year')->orderByDesc('claim_number')
            ->paginate($this->perPage());

        return $this->paged($page, ClaimResource::class);
    }

    #[OA\Post(path: '/api/v1/foreign-vat-refund/claims/preview', operationId: 'foreignVatRefundClaimsPreview', summary: 'What a claim for a country and period would contain and what still blocks it', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Preview')])]
    public function preview(PreviewClaimRequest $request, ClaimService $claims): JsonResponse
    {
        Gate::authorize('viewAny', VatRefundClaim::class);
        $data = $request->validated();

        return $this->successResponse($claims->preview($this->tenantId(), $data['target_country_code'], (int) $data['fiscal_year'], PeriodType::from($data['period_type']), $data['quarter'] ?? null, $data['ledger_ids'] ?? null), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/foreign-vat-refund/claims', operationId: 'foreignVatRefundClaimsStore', summary: 'Create a refund claim (dossier) from the eligible ledger lines of a country and period', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 201, description: 'Draft claim')])]
    public function store(CreateClaimRequest $request, ClaimService $claims): JsonResponse
    {
        Gate::authorize('create', VatRefundClaim::class);
        $claim = $claims->create($this->tenantId(), $request->validated(), $this->userId());

        return $this->createdResponse(new ClaimResource($claim->loadCount('items')), $this->message('claim_created'));
    }

    #[OA\Get(path: '/api/v1/foreign-vat-refund/claims/{id}', operationId: 'foreignVatRefundClaimsShow', summary: 'A claim with its lines and its event trail', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Claim')])]
    public function show(string $id): JsonResponse
    {
        $claim = VatRefundClaim::query()->with(['items.claim:id,claim_number', 'events'])->withCount('items')->findOrFail($id);
        Gate::authorize('view', $claim);

        return $this->successResponse(new ClaimResource($claim), $this->message('loaded'));
    }

    #[OA\Delete(path: '/api/v1/foreign-vat-refund/claims/{id}', operationId: 'foreignVatRefundClaimsDestroy', summary: 'Delete a claim not filed yet: its lines return to the eligible pool', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroy(string $id, ClaimService $claims): JsonResponse
    {
        $claim = VatRefundClaim::query()->findOrFail($id);
        Gate::authorize('delete', $claim);
        $claims->delete($claim, $this->userId());

        return $this->successResponse(null, $this->message('claim_deleted'));
    }

    #[OA\Post(path: '/api/v1/foreign-vat-refund/claims/{id}/ready', operationId: 'foreignVatRefundClaimsReady', summary: 'Mark the claim ready to submit after the compliance checks', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Claim')])]
    public function ready(NoInputRequest $request, string $id, ClaimService $claims): JsonResponse
    {
        return $this->transition($id, fn (VatRefundClaim $claim): VatRefundClaim => $claims->markReady($claim, $this->userId()), 'claim_ready');
    }

    #[OA\Post(path: '/api/v1/foreign-vat-refund/claims/{id}/draft', operationId: 'foreignVatRefundClaimsDraft', summary: 'Take a ready claim back to draft', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Claim')])]
    public function draft(NoInputRequest $request, string $id, ClaimService $claims): JsonResponse
    {
        return $this->transition($id, fn (VatRefundClaim $claim): VatRefundClaim => $claims->backToDraft($claim, $this->userId()), 'claim_drafted');
    }

    #[OA\Post(path: '/api/v1/foreign-vat-refund/claims/{id}/submit', operationId: 'foreignVatRefundClaimsSubmit', summary: 'Record the filing with the foreign authority and lock the lines', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Claim')])]
    public function submit(SubmitClaimRequest $request, string $id, ClaimService $claims): JsonResponse
    {
        $data = $request->validated();

        return $this->transition($id, fn (VatRefundClaim $claim): VatRefundClaim => $claims->submit($claim, $data['protocol_number'] ?? null, $data['submitted_on'] ?? null, $this->userId()), 'claim_submitted');
    }

    #[OA\Post(path: '/api/v1/foreign-vat-refund/claims/{id}/request-information', operationId: 'foreignVatRefundClaimsRequestInformation', summary: 'Log a request for additional information from the authority', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Claim')])]
    public function requestInformation(RequestInformationRequest $request, string $id, ClaimService $claims): JsonResponse
    {
        $note = (string) $request->validated()['note'];

        return $this->transition($id, fn (VatRefundClaim $claim): VatRefundClaim => $claims->requestInformation($claim, $note, $this->userId()), 'claim_information_requested');
    }

    #[OA\Post(path: '/api/v1/foreign-vat-refund/claims/{id}/decide', operationId: 'foreignVatRefundClaimsDecide', summary: 'Record the decision of the authority (approved, partially approved or rejected)', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Claim')])]
    public function decide(DecideClaimRequest $request, string $id, ClaimService $claims): JsonResponse
    {
        $data = $request->validated();

        return $this->transition($id, fn (VatRefundClaim $claim): VatRefundClaim => $claims->decide($claim, (string) $data['approved_amount'], $data['note'] ?? null, $this->userId()), 'claim_decided');
    }

    #[OA\Post(path: '/api/v1/foreign-vat-refund/claims/{id}/reconcile', operationId: 'foreignVatRefundClaimsReconcile', summary: 'Record the bank receipt: books the refund, the refused part and the exchange difference', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Claim')])]
    public function reconcile(ReconcileClaimRequest $request, string $id, ClaimService $claims): JsonResponse
    {
        $data = $request->validated();

        return $this->transition($id, fn (VatRefundClaim $claim): VatRefundClaim => $claims->reconcile($claim, $data, $this->userId()), 'claim_reconciled');
    }

    #[OA\Get(path: '/api/v1/foreign-vat-refund/claims/{id}/exports/{type}', operationId: 'foreignVatRefundClaimsExport', summary: 'Download the EU portal XML (eu-xml), the ESTV 1222/1223 package (estv-package) or the Modello IVA 79 package (iva79-package)', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'The file')])]
    public function export(string $id, string $type, ClaimExportService $exports): StreamedResponse
    {
        $claim = VatRefundClaim::query()->findOrFail($id);
        Gate::authorize('view', $claim);
        $file = $exports->generate($claim, $type, $this->userId());

        return $exports->disk()->download($file['path'], $file['filename'], ['Content-Type' => $file['mime']]);
    }

    /**
     * @param  callable(VatRefundClaim): VatRefundClaim  $action
     */
    private function transition(string $id, callable $action, string $message): JsonResponse
    {
        $claim = VatRefundClaim::query()->findOrFail($id);
        Gate::authorize('update', $claim);
        $result = $action($claim)->loadCount('items')->load(['events']);

        return $this->successResponse(new ClaimResource($result), $this->message($message));
    }
}
