<?php

declare(strict_types=1);

namespace Modules\DataExport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\DataExport\Http\Requests\ImportSpreadsheetRequest;
use Modules\DataExport\Services\SpreadsheetImportMapper;
use OpenApi\Attributes as OA;

/**
 * Import of a CSV or XLSX file into a dataset through a column mapper: preview the headers, then import (or dry-run) with the mapping.
 */
final class SpreadsheetImportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SpreadsheetImportMapper $mapper) {}

    /**
     * Preview a file.
     */
    #[OA\Post(
        path: '/api/v1/exports/imports/preview',
        operationId: 'previewSpreadsheetImport',
        description: 'Reads the headers and the first rows of a CSV (semicolon or comma) or XLSX file (first row = headers, at most 5000 rows) and suggests the mapping of the dataset fields to the headers. Nothing is stored.',
        summary: 'Preview an import file',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['dataset', 'file'], properties: [
            new OA\Property(property: 'dataset', type: 'string', enum: ['contacts']),
            new OA\Property(property: 'file', type: 'string', format: 'binary'),
        ]))),
        tags: ['Data Export'],
        responses: [
            new OA\Response(response: 200, description: 'Headers, sample rows and suggested mapping', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpreadsheetImportPreview')])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function preview(ImportSpreadsheetRequest $request): JsonResponse
    {
        return $this->successResponse($this->mapper->preview($request->file('file'), $request->validated('dataset')));
    }

    /**
     * Import a file.
     */
    #[OA\Post(
        path: '/api/v1/exports/imports',
        operationId: 'importSpreadsheet',
        description: 'Validates every row with the rules of the dataset and creates the valid ones; the others are reported by line number (the header is line 1) and never block the rest. A row whose VAT number or e-mail already exists is skipped as a duplicate. With `dry_run` nothing is written. `mapping` is the object of target field to file header, for example `mapping[email]=E-mail`.',
        summary: 'Import a CSV or XLSX file',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['dataset', 'file', 'mapping'], properties: [
            new OA\Property(property: 'dataset', type: 'string', enum: ['contacts']),
            new OA\Property(property: 'file', type: 'string', format: 'binary'),
            new OA\Property(property: 'mapping', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
            new OA\Property(property: 'dry_run', type: 'boolean'),
        ]))),
        tags: ['Data Export'],
        responses: [
            new OA\Response(response: 200, description: 'Outcome of the import', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpreadsheetImportResult')])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(ImportSpreadsheetRequest $request): JsonResponse
    {
        return $this->successResponse($this->mapper->import(
            $request->file('file'),
            $request->validated('dataset'),
            $request->validated('mapping'),
            $request->boolean('dry_run'),
        ), $request->boolean('dry_run') ? 'Import checked' : 'Import completed');
    }
}
