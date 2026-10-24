<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Retail\ClosePosSession;
use App\Actions\Retail\OpenPosSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Retail\ClosePosSessionRequest;
use App\Http\Requests\Retail\OpenPosSessionRequest;
use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use App\Models\PosSession;
use App\Services\Retail\PosSessionReport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PosSessionController extends Controller
{
    use ApiResponse;

    public function store(OpenPosSessionRequest $request, OpenPosSession $openSession): JsonResponse
    {
        $session = $openSession->execute($request->validated('pos_terminal_id'), (float) $request->validated('opening_float', 0));

        return $this->successResponse(new CatalogResource($session), __('pos.messages.session_opened'), Response::HTTP_CREATED);
    }

    public function close(ClosePosSessionRequest $request, ClosePosSession $closeSession, string $id): JsonResponse
    {
        $session = $closeSession->execute(PosSession::findOrFail($id), (float) $request->validated('closing_cash'));

        return $this->successResponse(new CatalogResource($session), __('pos.messages.session_closed'));
    }

    public function report(PosSessionReport $report, string $id): JsonResponse
    {
        return $this->successResponse($report->build(PosSession::findOrFail($id)), __('pos.messages.session_report'));
    }
}
