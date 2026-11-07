<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\EmployeeProfile;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class HrController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    #[OA\Get(
        path: '/api/v1/hr/frontalieri',
        operationId: 'getCrossBorderFrontalieri',
        description: 'Retrieve registered cross-border commuter employees (Frontalieri IT-CH).',
        summary: 'List cross-border employees',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['HR', 'Cross-Border'],
        responses: [
            new OA\Response(response: 200, description: 'Cross-border commuters retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function frontalieri(): JsonResponse
    {
        $frontalieri = EmployeeProfile::join('users', 'users.id', '=', 'employee_profiles.user_id')
            ->where('employee_profiles.is_cross_border_commuter', true)
            ->select([
                'employee_profiles.id',
                'employee_profiles.user_id',
                'employee_profiles.is_cross_border_commuter',
                'employee_profiles.job_title',
                'employee_profiles.department',
                'employee_profiles.hire_date',
                'employee_profiles.termination_date',
                'users.name',
                'users.email',
            ])
            ->get();

        return $this->successResponse(data: $frontalieri, message: 'Cross-border commuters (Frontalieri IT-CH) retrieved');
    }

    #[OA\Get(
        path: '/api/v1/hr/withholding-taxes',
        operationId: 'getWithholdingTaxes',
        description: 'Get applicable cross-border withholding tax parameters based on tenant fiscal jurisdiction.',
        summary: 'Get withholding tax parameters',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['HR', 'Cross-Border'],
        responses: [
            new OA\Response(response: 200, description: 'Withholding tax rates retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function withholdingTaxes(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();

        $rates = ($tenant?->country === 'CH') ? [
            'canton' => 'TI',
            'agreement' => 'New IT-CH Cross-border Agreement 2024',
            'swiss_withholding_limit' => '80% cantonal tax rate applied at source in Switzerland',
            'italian_tax_credit' => 'Applicable up to CHF 10,000 threshold with Italian IRPEF offsetting',
        ] : [
            'country' => 'IT',
            'inps_contributions' => 'Standard IVS 9.19% employee, 23.81% employer',
            'inail_rate' => 'Risk class tariff table',
        ];

        return $this->successResponse(data: $rates, message: 'Withholding tax rates and cross-border parameters');
    }
}
