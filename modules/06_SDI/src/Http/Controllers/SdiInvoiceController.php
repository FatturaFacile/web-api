<?php

declare(strict_types=1);

namespace Modules\SDI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Modules\SDI\Services\FatturaPaHtmlRenderer;
use Modules\SDI\Services\FatturaPaSchemaValidator;
use Modules\SDI\Services\SdiFileNamer;
use Modules\SDI\Services\SdiPreflight;
use Symfony\Component\HttpFoundation\Response;

class SdiInvoiceController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    /**
     * States of an invoice whose file already reached, or is reaching, SDI: it can no longer be regenerated.
     */
    private const array LOCKED = ['sending', 'delivered', 'accepted', 'not_delivered'];

    public function __construct(
        private readonly FatturaPaBuilderService $builder,
        private readonly FatturaPaSchemaValidator $schema,
        private readonly SdiPreflight $preflight,
        private readonly SdiFileNamer $fileNames,
        private readonly FatturaPaHtmlRenderer $renderer,
    ) {}

    /**
     * Build the FatturaPA file, validate it against the official schema and keep it on the invoice (state generated).
     */
    public function generateXml(string $id): JsonResponse
    {
        $invoice = $this->load($id);
        $this->authorize('sendSdi', $invoice);

        $metadata = $invoice->sdiMetadata()->first();

        if ($metadata !== null && in_array($metadata->sdi_status, self::LOCKED, true)) {
            return $this->problemDetailsResponse(
                title: __('sdi.send_failed_title'),
                detail: __('sdi.already_sent', ['status' => (string) $metadata->sdi_status]),
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $this->preflight->assertMasterData($invoice);

        // A file name is never consumed twice for the same invoice: regenerating keeps the name it already has.
        $name = $metadata?->file_name !== null
            ? ['file_name' => $metadata->file_name, 'progressive' => $this->fileNames->progressiveOf($metadata->file_name)]
            : $this->fileNames->next($invoice->tenant);

        $xml = $this->builder->buildFatturaPaXml($invoice, $name['progressive']);
        $errors = $this->schema->errors($xml);

        $metadata = $invoice->sdiMetadata()->updateOrCreate(['invoice_id' => $invoice->id], [
            'transmission_format' => $this->builder->transmissionFormat($invoice),
            'sdi_status' => 'generated',
            'file_name' => $name['file_name'],
            'xml_content' => $xml,
        ]);

        return $this->successResponse([
            'invoice_id' => $invoice->id,
            'file_name' => $name['file_name'],
            'sdi_status' => $metadata->sdi_status,
            'transmission_format' => $metadata->transmission_format,
            'valid' => $errors === [],
            'schema_errors' => $errors,
        ], __('sdi.messages.generated'));
    }

    /**
     * The stored file (or one built on the spot) as a sandboxed, read-only HTML page.
     */
    public function previewHtml(string $id): HttpResponse
    {
        $invoice = $this->load($id);
        $this->authorize('view', $invoice);

        $xml = $invoice->sdiMetadata()->value('xml_content') ?: $this->builder->buildFatturaPaXml($invoice);

        return response($this->renderer->render((string) $xml), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    /**
     * The stored file, for the manual channel and for archiving.
     */
    public function downloadXml(string $id): HttpResponse
    {
        $invoice = $this->load($id);
        $this->authorize('view', $invoice);

        $metadata = $invoice->sdiMetadata()->first();
        abort_if($metadata === null || ! $metadata->xml_content, Response::HTTP_NOT_FOUND);

        return response($metadata->xml_content, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.($metadata->file_name ?? 'invoice.xml').'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function load(string $id): Invoice
    {
        return Invoice::with(['tenant', 'contact.addresses', 'items', 'sdiMetadata', 'declarationOfIntent', 'sourcePassiveInvoice', 'relatedInvoice'])->findOrFail($id);
    }
}
