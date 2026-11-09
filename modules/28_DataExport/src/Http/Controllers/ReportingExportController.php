<?php

declare(strict_types=1);

namespace Modules\DataExport\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\DataExport\Services\ExcelCsvWriter;
use Modules\DataExport\Services\ReportingDatasets;
use OpenApi\Attributes as OA;

final class ReportingExportController extends Controller
{
    private const int MaxPageSize = 1000;

    public function __construct(private readonly ReportingDatasets $datasets) {}

    #[OA\Get(
        path: '/api/v1/reporting/feed/{dataset}',
        operationId: 'getReportingFeed',
        description: 'Read-only feed for Power BI and similar tools, in an OData-like JSON shape: `value`, `@odata.count` and `@odata.nextLink`, paged with `$top` and `$skip`. It is not a full OData service: there is no $metadata, $filter or $select. Authenticate with an API key with the `reporting.read` scope, as a bearer token or in `X-Api-Key`.',
        summary: 'Reporting feed',
        security: [['bearerAuth' => []]],
        tags: ['Data Export'],
        parameters: [
            new OA\Parameter(name: 'dataset', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['invoices', 'purchases', 'payments', 'contacts'])),
            new OA\Parameter(name: '$top', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 100, maximum: 1000)),
            new OA\Parameter(name: '$skip', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 0)),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of the dataset', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Invalid API key', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Scope missing', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function feed(Request $request, string $dataset): JsonResponse
    {
        $period = $this->period($request);
        $top = min(self::MaxPageSize, max(1, (int) $request->query('$top', '100')));
        $skip = max(0, (int) $request->query('$skip', '0'));

        $query = $this->datasets->query($dataset, $period);
        $count = (clone $query)->toBase()->getCountForPagination();
        $rows = $query->skip($skip)->take($top)->get()->map(fn (object $record): array => $this->datasets->row($dataset, $record))->all();

        $payload = ['@odata.count' => $count, 'value' => $rows];

        if ($skip + $top < $count) {
            $payload['@odata.nextLink'] = $request->fullUrlWithQuery(['$top' => $top, '$skip' => $skip + $top]);
        }

        return response()->json($payload);
    }

    #[OA\Get(
        path: '/api/v1/exports/csv/{dataset}',
        operationId: 'exportDatasetCsv',
        description: 'Excel-compatible CSV (semicolons, UTF-8 BOM) of invoices, purchases, payments (collections received on invoices, by payment date) or contacts, optionally for a period.',
        summary: 'Export dataset to CSV',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Data Export'],
        parameters: [
            new OA\Parameter(name: 'dataset', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['invoices', 'purchases', 'payments', 'contacts'])),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function csv(Request $request, string $dataset, ExcelCsvWriter $writer): Response
    {
        $rows = $this->datasets->query($dataset, $this->period($request))->lazyById(500, 'id')
            ->map(fn (object $record): array => array_values($this->datasets->row($dataset, $record)));

        return response($writer->write($this->datasets->columns($dataset), $rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$dataset}.csv\"",
        ]);
    }

    /**
     * @return array{from?: ?string, to?: ?string}
     */
    private function period(Request $request): array
    {
        return $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
    }
}
