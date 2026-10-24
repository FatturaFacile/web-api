<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DocumentEmail;
use App\Services\Pdf\DocumentPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DocumentPdfController extends Controller
{
    /**
     * Download (or display inline with `?inline=1`) the PDF of an invoice, quote, order, delivery note or work report.
     */
    public function show(Request $request, DocumentPdfService $pdf, string $type, string $id): Response
    {
        $class = DocumentEmail::TARGET_TYPES[$type] ?? null;

        abort_if($class === null, 404, __('pdf.messages.unsupported_type'));

        $rendered = $pdf->render($class::findOrFail($id));
        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';

        return response($rendered->content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$rendered->filename}\"",
            'Content-Length' => (string) strlen($rendered->content),
        ]);
    }
}
