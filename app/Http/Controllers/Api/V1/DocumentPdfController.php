<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DocumentEmail;
use App\Services\Pdf\DocumentPdfService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

class DocumentPdfController extends Controller
{
    use AuthorizesRequests;

    /**
     * Download (or display inline with `?inline=1`) the PDF of an invoice, quote, order, delivery note or work report.
     */
    #[OA\Get(
        path: '/api/v1/documents/{type}/{id}/pdf',
        operationId: 'downloadDocumentPdf',
        description: 'Download (or display inline with ?inline=1) the generated PDF of a sales document (invoice, quote, order, delivery_note, work_order).',
        summary: 'Download document PDF',
        security: [['bearerAuth' => []]],
        tags: ['Documents', 'PDF'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, description: 'Document type (invoice, quote, order, delivery_note, work_order)', schema: new OA\Schema(type: 'string', enum: ['invoice', 'quote', 'order', 'delivery_note', 'work_order'])),
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Document UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'inline', in: 'query', required: false, description: 'Stream inline in browser rather than attachment download', schema: new OA\Schema(type: 'boolean', default: false)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PDF binary stream', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Document not found or unsupported type', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request, DocumentPdfService $pdf, string $type, string $id): Response
    {
        $class = DocumentEmail::TARGET_TYPES[$type] ?? null;

        abort_if($class === null, 404, __('pdf.messages.unsupported_type'));

        $document = $class::findOrFail($id);
        $this->authorize('download', $document);

        $rendered = $pdf->render($document);
        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';

        return response($rendered->content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$rendered->filename}\"",
            'Content-Length' => (string) strlen($rendered->content),
        ]);
    }
}
