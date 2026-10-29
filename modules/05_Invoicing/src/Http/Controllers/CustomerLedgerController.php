<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Contracts\CustomerLedgerServiceInterface;
use Modules\Invoicing\Http\Resources\CustomerLedgerEntryResource;
use Modules\Invoicing\Models\CustomerLedgerEntry;

final class CustomerLedgerController extends Controller
{
    public function statement(string $customerId, Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();

        $entries = CustomerLedgerEntry::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->orderBy('entry_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->paginate((int) $request->query('per_page', 50));

        return response()->json(CustomerLedgerEntryResource::collection($entries)->response()->getData(true));
    }

    public function aging(string $customerId, CustomerLedgerServiceInterface $service, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $aging = $service->calculateAging($customerId, $tenantId);

        return response()->json([
            'customer_id' => $customerId,
            'tenant_id' => $tenantId,
            'aging_buckets' => $aging,
        ]);
    }
}
