<?php

declare(strict_types=1);

namespace Modules\DataExport\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Modules\DataExport\Services\AccountantArchiveBuilder;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class AccountantExportController extends Controller
{
    #[OA\Get(
        path: '/api/v1/exports/accountant-archive',
        operationId: 'downloadAccountantArchive',
        description: 'ZIP with the FatturaPA XML of the documents issued and received in a period of at most 366 days, and a CSV index. Documents without a stored XML are listed in the index as `xml_missing`.',
        summary: 'Download accountant archive',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Data Export'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The archive', content: new OA\MediaType(mediaType: 'application/zip', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid period', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function __invoke(Request $request, AccountantArchiveBuilder $builder): BinaryFileResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = CarbonImmutable::parse($validated['from']);
        $to = CarbonImmutable::parse($validated['to']);

        if ($from->diffInDays($to) > 366) {
            abort(422, 'The period cannot be longer than 366 days.');
        }

        return response()
            ->download($builder->build($from, $to), "documenti-{$validated['from']}-{$validated['to']}.zip", ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}
