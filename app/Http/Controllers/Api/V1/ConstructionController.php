<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ConformityCertificate;
use App\Models\ConstructionSite;
use App\Models\ProgressBilling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConstructionController extends Controller
{
    use ApiResponse;

    public function sites(): JsonResponse
    {
        $sites = ConstructionSite::latest()->paginate(25);

        return $this->successResponse(data: $sites, message: 'Construction sites (Cantieri) retrieved');
    }

    public function sal(Request $request): JsonResponse
    {
        $sals = ProgressBilling::latest()->paginate(25);

        return $this->successResponse(data: $sals, message: 'Progress billings (SAL) retrieved');
    }

    public function storeSal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'construction_site_id' => ['required', 'uuid'],
            'sal_number' => ['required', 'string'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date'],
            'gross_certified_amount' => ['required', 'numeric', 'min:0'],
            'retention_percentage' => ['nullable', 'numeric', 'min:0', 'max:50'],
        ]);

        $retentionPct = (float) ($validated['retention_percentage'] ?? 10.0);
        $gross = (float) $validated['gross_certified_amount'];
        $retention = round($gross * ($retentionPct / 100.0), 2);
        $net = $gross - $retention;

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
                'gross' => $gross,
                'retention' => $retention,
                'net_payable' => $net,
            ],
            message: 'SAL progress billing created successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    public function conformityCertificates(): JsonResponse
    {
        $certs = ConformityCertificate::latest('certified_at')->get();

        return $this->successResponse(data: $certs, message: 'Conformity certificates (DiCo / NIV) retrieved');
    }
}
