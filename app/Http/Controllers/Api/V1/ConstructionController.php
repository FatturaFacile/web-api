<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Construction\StoreSalRequest;
use App\Http\Responses\ApiResponse;
use App\Models\ConformityCertificate;
use App\Models\ConstructionSite;
use App\Models\ProgressBilling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Services\Tax\Decimal;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class ConstructionController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/construction/sites',
        operationId: 'getConstructionSites',
        description: 'List active construction project sites (Cantieri edili).',
        summary: 'List construction sites',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Construction'],
        responses: [
            new OA\Response(response: 200, description: 'Construction sites retrieved successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function sites(): JsonResponse
    {
        $sites = ConstructionSite::latest()->paginate(25);

        return $this->successResponse(data: $sites, message: 'Construction sites (Cantieri) retrieved');
    }

    #[OA\Get(
        path: '/api/v1/construction/sal',
        operationId: 'getProgressBillings',
        description: 'List progress billings (Stato Avanzamento Lavori - SAL) across construction projects.',
        summary: 'List progress billings (SAL)',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Construction'],
        responses: [
            new OA\Response(response: 200, description: 'Progress billings retrieved successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function sal(Request $request): JsonResponse
    {
        $sals = ProgressBilling::latest()->paginate(25);

        return $this->successResponse(data: $sals, message: 'Progress billings (SAL) retrieved');
    }

    #[OA\Post(
        path: '/api/v1/construction/sal',
        operationId: 'createProgressBilling',
        description: 'Create a new certified construction progress billing milestone with retention withholding.',
        summary: 'Create SAL progress billing',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Construction'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['construction_site_id', 'sal_number', 'period_start', 'period_end', 'gross_certified_amount'],
                properties: [
                    new OA\Property(property: 'construction_site_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'sal_number', type: 'string', example: 'SAL-01'),
                    new OA\Property(property: 'period_start', type: 'string', format: 'date'),
                    new OA\Property(property: 'period_end', type: 'string', format: 'date'),
                    new OA\Property(property: 'gross_certified_amount', type: 'number', format: 'float', example: 150000.00),
                    new OA\Property(property: 'retention_percentage', type: 'number', format: 'float', example: 10.0),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'SAL progress billing created successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeSal(StoreSalRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $retentionPct = (string) ($validated['retention_percentage'] ?? '10');
        $gross = Decimal::round2((string) $validated['gross_certified_amount']);
        $retention = Decimal::round2(Decimal::div(Decimal::mul($gross, $retentionPct), '100'));
        $net = Decimal::round2(Decimal::sub($gross, $retention));

        $billing = ProgressBilling::create([
            'construction_site_id' => $validated['construction_site_id'],
            'sal_number' => $validated['sal_number'],
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'gross_certified_amount' => $gross,
            'retention_amount' => $retention,
            'net_payable_amount' => $net,
            'status' => 'certified',
        ]);

        return $this->successResponse(
            data: [
                'id' => $billing->id,
                'gross' => (float) $gross,
                'retention' => (float) $retention,
                'net_payable' => (float) $net,
            ],
            message: 'SAL progress billing created successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    #[OA\Get(
        path: '/api/v1/construction/conformity-certificates',
        operationId: 'getConformityCertificates',
        description: 'List statutory certificates of conformity (Dichiarazioni di Conformità - DiCo) for technical plants.',
        summary: 'List conformity certificates',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Construction'],
        responses: [
            new OA\Response(response: 200, description: 'Conformity certificates retrieved successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function conformityCertificates(): JsonResponse
    {
        $certs = ConformityCertificate::latest()->paginate(25);

        return $this->successResponse(data: $certs, message: 'Conformity certificates (DICO) retrieved');
    }
}
