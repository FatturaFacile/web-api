<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Retail\RefundPosSale;
use App\Actions\Retail\RegisterPosSale;
use App\Actions\Retail\SyncPosSaleToRt;
use App\Http\Controllers\Controller;
use App\Http\Requests\Retail\StorePosSaleRequest;
use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use App\Models\PosSale;
use App\Models\PosSession;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PosSaleController extends Controller
{
    use ApiResponse;

    public function store(StorePosSaleRequest $request, RegisterPosSale $registerSale): JsonResponse
    {
        $data = $request->validated();
        $sale = $registerSale->execute(PosSession::findOrFail($data['pos_session_id']), $data);

        $payload = (new CatalogResource($sale))->resolve($request);

        if (isset($data['cash_tendered'])) {
            $payload['change_due'] = round((float) $data['cash_tendered'] - (float) $sale->cash_amount, 2);
        }

        return $this->successResponse($payload, __('pos.messages.sale_registered'), Response::HTTP_CREATED);
    }

    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource(PosSale::with('items')->findOrFail($id)), __('pos.messages.sale_retrieved'));
    }

    public function refund(RefundPosSale $refundSale, string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource($refundSale->execute(PosSale::findOrFail($id))), __('pos.messages.sale_refunded'));
    }

    public function rtTransmit(SyncPosSaleToRt $sync, string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource($sync->execute(PosSale::findOrFail($id))), __('pos.messages.rt_transmitted'), Response::HTTP_CREATED);
    }
}
