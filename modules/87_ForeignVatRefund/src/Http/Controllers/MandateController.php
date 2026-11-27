<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\ForeignVatRefund\Domain\Exceptions\ForeignVatException;
use Modules\ForeignVatRefund\Http\Controllers\Concerns\InteractsWithForeignVat;
use Modules\ForeignVatRefund\Http\Requests\SaveMandateRequest;
use Modules\ForeignVatRefund\Http\Requests\UploadAttachmentRequest;
use Modules\ForeignVatRefund\Http\Resources\MandateResource;
use Modules\ForeignVatRefund\Models\FiscalRepresentativeMandate;
use Modules\ForeignVatRefund\Services\AttachmentStore;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fiscal representative mandates (CH / IT) with the power of attorney and the certificate of taxable status (attestazione di soggettività passiva).
 */
final class MandateController extends Controller
{
    use ApiResponse;
    use InteractsWithForeignVat;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/foreign-vat-refund/mandates', operationId: 'foreignVatRefundMandatesIndex', summary: 'Fiscal representative mandates of the company', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Mandates')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', FiscalRepresentativeMandate::class);
        $page = FiscalRepresentativeMandate::query()->orderBy('country_code')->orderByDesc('mandate_start_date')->paginate($this->perPage(100));

        return $this->paged($page, MandateResource::class);
    }

    #[OA\Post(path: '/api/v1/foreign-vat-refund/mandates', operationId: 'foreignVatRefundMandatesStore', summary: 'Register a fiscal representative mandate', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 201, description: 'Mandate')])]
    public function store(SaveMandateRequest $request): JsonResponse
    {
        Gate::authorize('create', FiscalRepresentativeMandate::class);

        return $this->createdResponse(new MandateResource(FiscalRepresentativeMandate::query()->create($request->validated() + ['tenant_id' => $this->tenantId()])), $this->message('mandate_saved'));
    }

    #[OA\Get(path: '/api/v1/foreign-vat-refund/mandates/{id}', operationId: 'foreignVatRefundMandatesShow', summary: 'One mandate', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Mandate')])]
    public function show(string $id): JsonResponse
    {
        $mandate = FiscalRepresentativeMandate::query()->findOrFail($id);
        Gate::authorize('view', $mandate);

        return $this->successResponse(new MandateResource($mandate), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/foreign-vat-refund/mandates/{id}', operationId: 'foreignVatRefundMandatesUpdate', summary: 'Change a mandate', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Mandate')])]
    public function update(SaveMandateRequest $request, string $id): JsonResponse
    {
        $mandate = FiscalRepresentativeMandate::query()->findOrFail($id);
        Gate::authorize('update', $mandate);
        $mandate->update($request->validated());

        return $this->successResponse(new MandateResource($mandate->refresh()), $this->message('mandate_saved'));
    }

    #[OA\Delete(path: '/api/v1/foreign-vat-refund/mandates/{id}', operationId: 'foreignVatRefundMandatesDestroy', summary: 'Delete a mandate', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $mandate = FiscalRepresentativeMandate::query()->findOrFail($id);
        Gate::authorize('delete', $mandate);
        $mandate->delete();

        return $this->successResponse(null, $this->message('mandate_deleted'));
    }

    #[OA\Post(path: '/api/v1/foreign-vat-refund/mandates/{id}/documents', operationId: 'foreignVatRefundMandatesUploadDocument', summary: 'Upload the power of attorney or the certificate of taxable status (with its expiry date)', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Mandate')])]
    public function uploadDocument(UploadAttachmentRequest $request, string $id, AttachmentStore $store): JsonResponse
    {
        $mandate = FiscalRepresentativeMandate::query()->findOrFail($id);
        Gate::authorize('update', $mandate);
        $data = $request->validated();
        $type = (string) ($data['type'] ?? 'certificate');
        $path = $store->store($request->file('file'), $this->tenantId(), 'mandates/'.$mandate->id);

        if ($type === 'power_of_attorney') {
            $store->delete($mandate->power_of_attorney_document_path);
            $mandate->update(['power_of_attorney_document_path' => $path]);
        } else {
            $store->delete($mandate->certificate_of_taxable_status_path);
            $mandate->update(['certificate_of_taxable_status_path' => $path, 'certificate_expiry_date' => $data['certificate_expiry_date'] ?? $mandate->certificate_expiry_date]);
        }

        return $this->successResponse(new MandateResource($mandate->refresh()), $this->message('document_saved'));
    }

    #[OA\Get(path: '/api/v1/foreign-vat-refund/mandates/{id}/documents/{type}', operationId: 'foreignVatRefundMandatesDownloadDocument', summary: 'Download the power of attorney (power_of_attorney) or the certificate of taxable status (certificate)', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'The file')])]
    public function downloadDocument(string $id, string $type, AttachmentStore $store): StreamedResponse
    {
        $mandate = FiscalRepresentativeMandate::query()->findOrFail($id);
        Gate::authorize('view', $mandate);

        $path = match ($type) {
            'power_of_attorney' => $mandate->power_of_attorney_document_path,
            'certificate' => $mandate->certificate_of_taxable_status_path,
            default => throw ForeignVatException::because('file_not_found', [], 404),
        };

        return $store->download($path, $type.'-'.$mandate->country_code.'.'.pathinfo((string) $path, PATHINFO_EXTENSION));
    }
}
