<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Mobile\ReceiptTextParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class MobileReceiptController extends Controller
{
    use ApiResponse;

    /** Turns on-device OCR text of a receipt into expense fields (vendor, date, total, VAT). */
    #[OA\Post(
        path: '/api/v1/mobile/receipts/parse',
        operationId: 'parseMobileReceipt',
        description: 'Turns the on-device OCR text of a receipt into expense fields (vendor, date, total, VAT).',
        summary: 'Parse receipt text',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['text'], properties: [new OA\Property(property: 'text', type: 'string', maxLength: 10000)])),
        responses: [
            new OA\Response(response: 200, description: 'Parsed receipt fields', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function parse(Request $request, ReceiptTextParser $parser): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:10000']]);

        return $this->successResponse($parser->parse($data['text']), 'Receipt parsed');
    }
}
