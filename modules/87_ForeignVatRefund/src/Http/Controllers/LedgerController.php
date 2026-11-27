<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\ForeignVatRefund\Domain\Enums\LedgerStatus;
use Modules\ForeignVatRefund\Domain\Enums\SourceType;
use Modules\ForeignVatRefund\Domain\Exceptions\ForeignVatException;
use Modules\ForeignVatRefund\Http\Controllers\Concerns\InteractsWithForeignVat;
use Modules\ForeignVatRefund\Http\Requests\NoInputRequest;
use Modules\ForeignVatRefund\Http\Requests\SaveLedgerEntryRequest;
use Modules\ForeignVatRefund\Http\Requests\UpdateLedgerEntryRequest;
use Modules\ForeignVatRefund\Http\Requests\UploadAttachmentRequest;
use Modules\ForeignVatRefund\Http\Resources\LedgerEntryResource;
use Modules\ForeignVatRefund\Models\ForeignVatLedgerEntry;
use Modules\ForeignVatRefund\Services\AttachmentStore;
use Modules\ForeignVatRefund\Services\ForeignVatInterceptor;
use Modules\ForeignVatRefund\Services\LedgerQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The foreign VAT ledger: filter, inspect, change the expense category, recalculate the eligible share, attach the scan of the original
 * invoice and register an item by hand.
 */
final class LedgerController extends Controller
{
    use ApiResponse;
    use InteractsWithForeignVat;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/foreign-vat-refund/ledger', operationId: 'foreignVatRefundLedgerIndex', summary: 'Foreign VAT ledger, filterable by country, status, category, year, quarter and source', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Paged ledger lines')])]
    public function index(Request $request, LedgerQueryService $query): JsonResponse
    {
        Gate::authorize('viewAny', ForeignVatLedgerEntry::class);

        return $this->paged($query->paginate($request->only(['country', 'status', 'category', 'fiscal_year', 'fiscal_quarter', 'source_type', 'claim_id', 'search']), $this->perPage()), LedgerEntryResource::class);
    }

    #[OA\Get(path: '/api/v1/foreign-vat-refund/ledger/{id}', operationId: 'foreignVatRefundLedgerShow', summary: 'One ledger line', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Ledger line')])]
    public function show(string $id): JsonResponse
    {
        $entry = ForeignVatLedgerEntry::query()->with('claim:id,claim_number')->findOrFail($id);
        Gate::authorize('view', $entry);

        return $this->successResponse(new LedgerEntryResource($entry), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/foreign-vat-refund/ledger', operationId: 'foreignVatRefundLedgerStore', summary: 'Register a foreign VAT item by hand', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 201, description: 'Ledger line')])]
    public function store(SaveLedgerEntryRequest $request, ForeignVatInterceptor $interceptor): JsonResponse
    {
        Gate::authorize('create', ForeignVatLedgerEntry::class);
        $entry = $interceptor->registerManual($this->tenantId(), $request->validated(), $request->boolean('post_to_ledger', true));

        return $this->createdResponse(new LedgerEntryResource($entry), $this->message('entry_created'));
    }

    #[OA\Patch(path: '/api/v1/foreign-vat-refund/ledger/{id}', operationId: 'foreignVatRefundLedgerUpdate', summary: 'Change the expense category (or country) of an open line and recalculate the eligible share', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Recalculated line')])]
    public function update(UpdateLedgerEntryRequest $request, string $id, ForeignVatInterceptor $interceptor): JsonResponse
    {
        $entry = ForeignVatLedgerEntry::query()->findOrFail($id);
        Gate::authorize('update', $entry);
        $data = $request->validated();

        return $this->successResponse(new LedgerEntryResource($interceptor->reclassify($entry, $data['expense_category_code'] ?? null, $data['supplier_country_code'] ?? null)), $this->message('entry_updated'));
    }

    #[OA\Post(path: '/api/v1/foreign-vat-refund/ledger/{id}/recalculate', operationId: 'foreignVatRefundLedgerRecalculate', summary: 'Recalculate the eligible share of an open line with the current matrix', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Recalculated line')])]
    public function recalculate(NoInputRequest $request, string $id, ForeignVatInterceptor $interceptor): JsonResponse
    {
        $entry = ForeignVatLedgerEntry::query()->findOrFail($id);
        Gate::authorize('update', $entry);

        return $this->successResponse(new LedgerEntryResource($interceptor->reclassify($entry)), $this->message('entry_updated'));
    }

    #[OA\Delete(path: '/api/v1/foreign-vat-refund/ledger/{id}', operationId: 'foreignVatRefundLedgerDestroy', summary: 'Delete a manual line that is still open', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $entry = ForeignVatLedgerEntry::query()->findOrFail($id);
        Gate::authorize('delete', $entry);

        if ($entry->source_type !== SourceType::Manual || $entry->status !== LedgerStatus::PendingReview || $entry->journal_entry_id !== null) {
            throw ForeignVatException::because('ledger_entry_locked');
        }

        $entry->delete();

        return $this->successResponse(null, $this->message('entry_deleted'));
    }

    #[OA\Post(path: '/api/v1/foreign-vat-refund/ledger/{id}/attachment', operationId: 'foreignVatRefundLedgerUploadAttachment', summary: 'Attach the scan of the original invoice or receipt', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Ledger line')])]
    public function uploadAttachment(UploadAttachmentRequest $request, string $id, AttachmentStore $store): JsonResponse
    {
        $entry = ForeignVatLedgerEntry::query()->findOrFail($id);
        Gate::authorize('update', $entry);

        if ($entry->status->value === 'REFUNDED') {
            throw ForeignVatException::because('ledger_entry_locked');
        }

        $previous = $entry->attachment_path;
        $entry->update(['attachment_path' => $store->store($request->file('file'), $this->tenantId(), 'scans')]);
        $store->delete($previous);

        return $this->successResponse(new LedgerEntryResource($entry->refresh()), $this->message('attachment_saved'));
    }

    #[OA\Get(path: '/api/v1/foreign-vat-refund/ledger/{id}/attachment', operationId: 'foreignVatRefundLedgerDownloadAttachment', summary: 'Download or preview the scan of the original invoice', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'The file')])]
    public function downloadAttachment(string $id, AttachmentStore $store): StreamedResponse
    {
        $entry = ForeignVatLedgerEntry::query()->findOrFail($id);
        Gate::authorize('view', $entry);

        return $store->download($entry->attachment_path, 'invoice-'.preg_replace('/[^A-Za-z0-9_-]/', '_', $entry->document_number).'.'.pathinfo((string) $entry->attachment_path, PATHINFO_EXTENSION));
    }
}
