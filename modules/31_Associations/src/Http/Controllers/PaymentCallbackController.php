<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\OpenApi\OperationArguments;
use Modules\Associations\Services\PaymentLinkService;

/**
 * Where the online payment provider confirms a payment. The driver authenticates the call.
 */
final class PaymentCallbackController extends Controller
{
    public function __construct(private readonly PaymentLinkService $paymentLinks) {}

    #[AssociationPost('/api/v1/association-payments/callback', 'handleAssociationPaymentCallback', 'Receive the outcome of an online payment', 'AssociationPaymentCallbackRequest', OperationArguments::Public)]
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['data' => ['status' => $this->paymentLinks->handleCallback($request)->status]]);
    }
}
