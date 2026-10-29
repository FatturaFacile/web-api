<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Actions\AcceptSalesQuoteAction;
use Modules\Invoicing\Actions\ConvertQuoteToOrderAction;
use Modules\Invoicing\Actions\CreateSalesQuoteAction;
use Modules\Invoicing\Http\Requests\CreateSalesQuoteRequest;
use Modules\Invoicing\Http\Resources\SalesOrderResource;
use Modules\Invoicing\Http\Resources\SalesQuoteResource;
use Modules\Invoicing\Models\SalesQuote;

final class SalesQuoteController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $query = SalesQuote::with('items')->where('tenant_id', $tenantId);

        if ($request->has('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if ($request->has('contact_id')) {
            $query->where('contact_id', (string) $request->query('contact_id'));
        }

        $quotes = $query->orderBy('created_at', 'desc')->paginate((int) $request->query('per_page', 25));

        return response()->json(SalesQuoteResource::collection($quotes)->response()->getData(true));
    }

    public function store(
        CreateSalesQuoteRequest $request,
        CreateSalesQuoteAction $action,
        TenantContext $tenantContext
    ): JsonResponse {
        $validated = $request->validated();
        $items = $validated['items'];
        unset($validated['items']);

        $data = array_merge($validated, [
            'tenant_id' => $tenantContext->getTenantId(),
            'created_by' => $request->user()?->id,
        ]);

        $quote = $action->execute($data, $items);

        return response()->json(new SalesQuoteResource($quote), 201);
    }

    public function show(string $id): JsonResponse
    {
        $quote = SalesQuote::with('items')->findOrFail($id);

        return response()->json(new SalesQuoteResource($quote));
    }

    public function accept(string $id, AcceptSalesQuoteAction $action): JsonResponse
    {
        $quote = SalesQuote::findOrFail($id);
        $accepted = $action->execute($quote);

        return response()->json(new SalesQuoteResource($accepted));
    }

    public function convert(string $id, ConvertQuoteToOrderAction $action, Request $request): JsonResponse
    {
        $quote = SalesQuote::findOrFail($id);
        $userId = (string) ($request->user()?->id ?? 'system');

        $order = $action->execute($quote, $userId);

        return response()->json(new SalesOrderResource($order), 201);
    }
}
