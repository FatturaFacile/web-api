<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Exceptions\LabelPrintingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\PrintLabelsRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Inventory\LabelPresetRegistry;
use App\Services\Inventory\LabelPrintingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

#[OA\Tag(name: 'Inventory Labels', description: 'Product label generation and printing presets')]
class ProductLabelController extends Controller
{
    use ApiResponse;

    /**
     * Factory presets plus the custom presets of the current tenant.
     */
    #[OA\Get(
        path: '/api/v1/inventory/labels/presets',
        summary: 'List label presets',
        description: 'Get factory presets and custom presets for the current tenant',
        tags: ['Inventory Labels'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Presets retrieved successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function presets(LabelPresetRegistry $registry): JsonResponse
    {
        return $this->successResponse($registry->all(), __('labels.messages.presets'));
    }

    /**
     * Print a sheet of product labels as PDF (download by default, `?inline=1` to display it in the browser).
     */
    #[OA\Post(
        path: '/api/v1/inventory/labels/print',
        summary: 'Print product labels',
        description: 'Render product labels as PDF',
        tags: ['Inventory Labels'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'layout', type: 'object'),
                    new OA\Property(property: 'options', type: 'object'),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'PDF stream of rendered labels'),
            new OA\Response(response: 422, description: 'Label printing failed or unprocessable items'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function print(PrintLabelsRequest $request, LabelPrintingService $labels): Response|JsonResponse
    {
        try {
            $pdf = $labels->render($request->layoutConfig(), $request->contentOptions(), $request->labelItems());
        } catch (LabelPrintingException $e) {
            return $this->problemDetailsResponse(
                title: __('labels.problem_titles.labels'),
                detail: $e->getMessage(),
                status: HttpResponse::HTTP_UNPROCESSABLE_ENTITY,
                invalidParams: array_map(fn (array $failure): array => [
                    'product_id' => $failure['product_id'],
                    'reason' => __('labels.errors.product_not_encodable', ['sku' => $failure['sku'], 'reason' => $failure['reason']]),
                ], $e->failures)
            );
        }

        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';

        return response($pdf->content, HttpResponse::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$pdf->filename}\"",
            'Content-Length' => (string) strlen($pdf->content),
        ]);
    }
}
