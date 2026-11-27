<?php

declare(strict_types=1);

namespace Modules\DownPayments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\DownPayments\Domain\Enums\DepositResolution;
use Modules\DownPayments\Http\Controllers\Concerns\InteractsWithDownPayments;
use Modules\DownPayments\Http\Requests\ConfirmPaymentRequest;
use Modules\DownPayments\Http\Requests\ResolveDepositRequest;
use Modules\DownPayments\Http\Resources\DepositResource;
use Modules\DownPayments\Services\ChainReadService;
use Modules\DownPayments\Services\DepositService;
use OpenApi\Attributes as OA;

/**
 * Earnest money (caparra confirmatoria) and security deposits of a chain: the collection of a receipt and the resolution wizard
 * (impute to the price with VAT, forfeit on breach outside the VAT scope, refund single or double under Art. 1385 c.c.).
 */
final class DepositController extends Controller
{
    use ApiResponse;
    use InteractsWithDownPayments;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly DepositService $deposits, private readonly ChainReadService $reader) {}

    #[OA\Post(path: '/api/v1/down-payment-chains/{id}/deposits/{depositId}/confirm-payment', operationId: 'confirmDepositPayment', summary: 'Records the payment of a deposit receipt: the deposit is held and its liability is posted', security: self::SECURITY, tags: ['DownPayments'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'depositId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The deposit')])]
    public function confirmPayment(ConfirmPaymentRequest $request, string $id, string $depositId): JsonResponse
    {
        $chain = $this->chain($id);
        Gate::authorize('emit', $chain);
        $deposit = $this->deposits->confirmPayment($chain, $this->deposit($chain, $depositId), (string) $request->validated('paid_at'), $this->userId(), $request->validated('payment_account'));

        return $this->successResponse(new DepositResource($deposit), $this->message('deposit_collected'));
    }

    #[OA\Post(path: '/api/v1/down-payment-chains/{id}/deposits/{depositId}/preview-resolution', operationId: 'previewDepositResolution', summary: 'The accounting impact of a resolution of a held deposit, without posting it', security: self::SECURITY, tags: ['DownPayments'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'depositId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The preview')])]
    public function preview(ResolveDepositRequest $request, string $id, string $depositId): JsonResponse
    {
        $chain = $this->chain($id);
        Gate::authorize('view', $chain);
        $data = $request->validated();

        return $this->successResponse($this->deposits->preview($chain, $this->deposit($chain, $depositId), DepositResolution::from($data['resolution']), ($data['refund_mode'] ?? 'single') === 'double'), $this->message('previewed'));
    }

    #[OA\Post(path: '/api/v1/down-payment-chains/{id}/deposits/{depositId}/resolve', operationId: 'resolveDeposit', summary: 'Resolves a held deposit: impute_to_price, forfeit_breach or refund (single or double)', security: self::SECURITY, tags: ['DownPayments'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'depositId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The chain'), new OA\Response(response: 422, description: 'Rule refused')])]
    public function resolve(ResolveDepositRequest $request, string $id, string $depositId): JsonResponse
    {
        $chain = $this->chain($id);
        Gate::authorize('resolveDeposit', $chain);
        $this->deposits->resolve($chain, $this->deposit($chain, $depositId), $request->validated(), $this->userId());

        return $this->successResponse($this->reader->detail($chain->refresh()), $this->message('deposit_resolved'));
    }
}
