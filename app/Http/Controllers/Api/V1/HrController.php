<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HrController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function employees(): JsonResponse
    {
        $employees = DB::table('employee_profiles')
            ->join('users', 'users.id', '=', 'employee_profiles.user_id')
            ->where('employee_profiles.tenant_id', $this->tenantContext->getTenantId())
            ->whereNull('employee_profiles.deleted_at')
            ->select('employee_profiles.*', 'users.name', 'users.email', 'users.phone')
            ->get();

        return $this->successResponse(data: $employees, message: 'Employee profiles retrieved');
    }

    public function frontalieri(): JsonResponse
    {
        $frontalieri = DB::table('employee_profiles')
            ->join('users', 'users.id', '=', 'employee_profiles.user_id')
            ->where('employee_profiles.tenant_id', $this->tenantContext->getTenantId())
            ->where('employee_profiles.is_cross_border_commuter', true)
            ->whereNull('employee_profiles.deleted_at')
            ->select('employee_profiles.*', 'users.name', 'users.email')
            ->get();

        return $this->successResponse(data: $frontalieri, message: 'Cross-border commuters (Frontalieri IT-CH) retrieved');
    }

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
