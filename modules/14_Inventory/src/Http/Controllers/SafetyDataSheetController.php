<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\SafetyDataSheet;
use Modules\Inventory\Models\SafetyDataSheetDelivery;
use Modules\Inventory\Models\SafetyDataSheetVersion;
use Modules\Inventory\Services\SafetyDataSheetService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[OA\Tag(name: 'Inventory Safety Data Sheets', description: 'Safety data sheets per product with hazard classes, revision history and sending with deliveries')]
final class SafetyDataSheetController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/safety-sheets',
        summary: 'List safety data sheets',
        tags: ['Inventory Safety Data Sheets'],
        parameters: [new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Sheets with their current version', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SafetyDataSheet'))])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List safety data sheets', tags: ['Inventory'])]
    public function index(Request $request): JsonResponse
    {
        $sheets = SafetyDataSheet::query()
            ->with('currentVersion')
            ->when($request->filled('product_id'), static fn ($query) => $query->where('product_id', $request->query('product_id')))
            ->orderBy('title')
            ->get();

        return response()->json(['data' => $sheets]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/safety-sheets',
        summary: 'Create a safety data sheet',
        description: 'The sheet of a product in one language. Its file arrives as a first version.',
        tags: ['Inventory Safety Data Sheets'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['product_id', 'language', 'title'],
            properties: [
                new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'language', type: 'string', example: 'it'),
                new OA\Property(property: 'title', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Sheet created', content: new OA\JsonContent(ref: '#/components/schemas/SafetyDataSheet')),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Create a safety data sheet', tags: ['Inventory'])]
    public function store(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $validated = $request->validate([
            'product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'language' => ['required', 'string', 'max:5', Rule::unique('safety_data_sheets', 'language')->where('tenant_id', $tenantId)->where('product_id', $request->input('product_id'))],
            'title' => ['required', 'string', 'max:200'],
        ]);

        return response()->json(SafetyDataSheet::create($validated), 201);
    }

    #[OA\Post(
        path: '/api/v1/inventory/safety-sheets/{id}/versions',
        summary: 'Add a sheet revision',
        description: 'Uploads a revision, which becomes the current one. Earlier ones stay available. Hazard classes are stored as written on the sheet.',
        tags: ['Inventory Safety Data Sheets'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(
            required: ['file', 'revision_date', 'hazard_classes'],
            properties: [
                new OA\Property(property: 'file', type: 'string', format: 'binary'),
                new OA\Property(property: 'revision_date', type: 'string', format: 'date'),
                new OA\Property(property: 'hazard_classes', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'signal_word', type: 'string'),
                new OA\Property(property: 'notes', type: 'string'),
            ]
        ))),
        responses: [
            new OA\Response(response: 201, description: 'Revision added', content: new OA\JsonContent(ref: '#/components/schemas/SafetyDataSheetVersion')),
            new OA\Response(response: 404, description: 'Sheet not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Add a sheet revision', tags: ['Inventory'])]
    public function addVersion(string $id, Request $request, SafetyDataSheetService $sheets): JsonResponse
    {
        $sheet = SafetyDataSheet::findOrFail($id);
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'revision_date' => ['required', 'date'],
            'hazard_classes' => ['present', 'array'],
            'hazard_classes.*' => ['string', 'max:100'],
            'signal_word' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($sheets->addVersion($sheet, $request->file('file'), $validated, (string) auth()->id()), 201);
    }

    #[OA\Get(
        path: '/api/v1/inventory/safety-sheets/{id}/versions',
        summary: 'Version history of a sheet',
        tags: ['Inventory Safety Data Sheets'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Revisions, newest first', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SafetyDataSheetVersion'))])),
            new OA\Response(response: 404, description: 'Sheet not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Version history of a sheet', tags: ['Inventory'])]
    public function versions(string $id): JsonResponse
    {
        return response()->json(['data' => SafetyDataSheet::findOrFail($id)->versions()->get()]);
    }

    #[OA\Get(
        path: '/api/v1/inventory/safety-sheets/versions/{versionId}/file',
        summary: 'Download a sheet revision',
        tags: ['Inventory Safety Data Sheets'],
        parameters: [new OA\Parameter(name: 'versionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The PDF file of the revision, sent as an attachment named after the uploaded file', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 404, description: 'Revision not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Download a sheet revision', tags: ['Inventory'])]
    public function download(string $versionId): StreamedResponse
    {
        $version = SafetyDataSheetVersion::findOrFail($versionId);

        return Storage::disk($version->disk)->download($version->path, $version->original_name);
    }

    #[OA\Post(
        path: '/api/v1/inventory/safety-sheets/deliveries/{deliveryNoteId}/send',
        summary: 'Send the sheets of a delivery',
        description: 'Sends what did not go out when the delivery note was issued. Versions already sent are skipped. Delivery notes issued through the legacy sales flow have no issuing step, so they are sent from here.',
        tags: ['Inventory Safety Data Sheets'],
        parameters: [new OA\Parameter(name: 'deliveryNoteId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Outcome', content: new OA\JsonContent(properties: [new OA\Property(property: 'sent', type: 'integer'), new OA\Property(property: 'status', type: 'string', enum: ['sent', 'skipped', 'failed', 'nothing_to_send'])])),
            new OA\Response(response: 404, description: 'Delivery note not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Send the sheets of a delivery', tags: ['Inventory'])]
    public function send(string $deliveryNoteId, SafetyDataSheetService $sheets): JsonResponse
    {
        return response()->json($sheets->sendForDelivery($deliveryNoteId));
    }

    #[OA\Get(
        path: '/api/v1/inventory/safety-sheets/deliveries/{deliveryNoteId}',
        summary: 'What was sent with a delivery',
        tags: ['Inventory Safety Data Sheets'],
        parameters: [new OA\Parameter(name: 'deliveryNoteId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Sending log', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SafetyDataSheetDelivery'))])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'What was sent with a delivery', tags: ['Inventory'])]
    public function deliveries(string $deliveryNoteId): JsonResponse
    {
        return response()->json(['data' => SafetyDataSheetDelivery::query()->where('delivery_note_id', $deliveryNoteId)->with('version')->get()]);
    }
}
