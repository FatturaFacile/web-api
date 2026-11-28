<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\PaymentReminder;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Services\Documents\RecordAccess;
use App\Services\Pdf\DocumentPdfService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Platform\Localization\Domain\Fiscal\ItalianFiscalLocalization;
use Modules\Platform\Localization\Http\Requests\UpdateDocumentLocaleRequest;
use Modules\Platform\Localization\Infrastructure\Persistence\TenantLocaleSettingsRepository;
use OpenApi\Attributes as OA;

/**
 * The print language of sales and purchase documents: frozen when the document is created, editable only while it is a
 * draft, plus the Italian translation print of a foreign-language invoice issued by an Italian company (Art. 21 DPR 633/72).
 */
final class DocumentLocaleController extends Controller
{
    use RespondsWithEnvelope;

    /** @var array<string, class-string<Model>> */
    public const array TYPES = [
        'invoice' => Invoice::class,
        'quote' => Quote::class,
        'delivery_note' => DeliveryNote::class,
        'payment_reminder' => PaymentReminder::class,
        'purchase_order' => PurchaseOrder::class,
    ];

    public function __construct(private readonly RecordAccess $recordAccess) {}

    #[OA\Get(
        path: '/api/v1/documents/{type}/{id}/locale',
        operationId: 'getDocumentLocale',
        description: 'The print language frozen on a document and whether it can still be changed (drafts only).',
        summary: 'Get the document locale',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Localization'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['invoice', 'quote', 'delivery_note', 'payment_reminder', 'purchase_order'])),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The document locale', content: new OA\JsonContent(ref: '#/components/schemas/LocalizationDocumentLocaleResponse')),
            new OA\Response(ref: '#/components/responses/LocalizationUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/LocalizationNotFound', response: 404),
        ]
    )]
    public function show(Request $request, string $type, string $id): JsonResponse
    {
        $document = $this->document($type, $id);
        $this->recordAccess->authorize($request->user(), $document, 'read');

        return $this->envelope($this->payload($document), __('localization.messages.document_locale'));
    }

    #[OA\Put(
        path: '/api/v1/documents/{type}/{id}/locale',
        operationId: 'updateDocumentLocale',
        description: 'Changes the print language of a draft document. An issued document keeps the language it was issued in and answers 422.',
        summary: 'Update the document locale',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Localization'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['invoice', 'quote', 'delivery_note', 'payment_reminder', 'purchase_order'])),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['locale'], properties: [new OA\Property(property: 'locale', type: 'string', example: 'de-CH')])),
        responses: [
            new OA\Response(response: 200, description: 'The document locale', content: new OA\JsonContent(ref: '#/components/schemas/LocalizationDocumentLocaleResponse')),
            new OA\Response(ref: '#/components/responses/LocalizationUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/LocalizationNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/LocalizationUnprocessable', response: 422),
        ]
    )]
    public function update(UpdateDocumentLocaleRequest $request, string $type, string $id): JsonResponse
    {
        $document = $this->document($type, $id);
        $this->recordAccess->authorize($request->user(), $document, 'write', 'update');

        $document->setAttribute('locale', (string) $request->validated('locale'));
        $document->save();

        return $this->envelope($this->payload($document->refresh()), __('localization.messages.document_locale_updated'));
    }

    #[OA\Get(
        path: '/api/v1/sales/invoices/{id}/italian-translation-print',
        operationId: 'printInvoiceItalianTranslation',
        description: 'PDF of an issued invoice in Italian ("Stampa traduzione italiana"), for Italian companies that issued it in another language (Art. 21 DPR 633/72). Answers 422 for an invoice that is not issued, already in Italian, or of a non-Italian company.',
        summary: 'Print the Italian translation of an invoice',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Localization'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'PDF binary stream', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(ref: '#/components/responses/LocalizationUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/LocalizationNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/LocalizationUnprocessable', response: 422),
        ]
    )]
    public function italianTranslation(Request $request, string $id, DocumentPdfService $pdf, TenantLocaleSettingsRepository $settings): Response|JsonResponse
    {
        $invoice = Invoice::query()->findOrFail($id);
        $this->recordAccess->authorize($request->user(), $invoice, 'read', 'download');

        $locale = (string) $invoice->getAttribute('locale');
        $strategy = new ItalianFiscalLocalization;

        if ($settings->country($this->tenantId()) !== 'IT' || $invoice->status === 'draft' || ! $strategy->requiresItalianTranslation($locale)) {
            return $this->problem(422, __('localization.translation_print.unavailable_title'), __('localization.translation_print.unavailable'));
        }

        $rendered = $pdf->render($invoice, ['locale' => 'it']);

        return response($rendered->content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="IT-'.$rendered->filename.'"',
            'Content-Length' => (string) strlen($rendered->content),
        ]);
    }

    private function document(string $type, string $id): Model
    {
        $class = self::TYPES[$type] ?? null;
        abort_if($class === null, 404);

        return $class::query()->findOrFail($id);
    }

    /**
     * @return array{type: string, id: string, locale: string|null, is_editable: bool}
     */
    private function payload(Model $document): array
    {
        $locale = $document->getAttribute('locale');

        return [
            'type' => (string) array_search($document::class, self::TYPES, true),
            'id' => (string) $document->getKey(),
            'locale' => is_string($locale) ? $locale : null,
            'is_editable' => method_exists($document, 'isDocumentLocaleEditable') && $document->isDocumentLocaleEditable(),
        ];
    }
}
