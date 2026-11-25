<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\CorporateConventions\Application\Commands\ReconcileWelfareVoucherBatchCommand;
use Modules\CorporateConventions\Application\Commands\ReconcileWelfareVoucherBatchHandler;
use Modules\CorporateConventions\Application\Commands\RedeemWelfareVoucherCommand;
use Modules\CorporateConventions\Application\Commands\RedeemWelfareVoucherHandler;
use Modules\CorporateConventions\Domain\Enums\WelfareProvider;
use Modules\CorporateConventions\Http\Controllers\Concerns\InteractsWithConventions;
use Modules\CorporateConventions\Http\Requests\ListFiltersRequest;
use Modules\CorporateConventions\Http\Requests\ReconcileVouchersRequest;
use Modules\CorporateConventions\Http\Requests\RedeemVoucherRequest;
use Modules\CorporateConventions\Http\Resources\WelfareRedemptionResource;
use Modules\CorporateConventions\Models\WelfareVoucherRedemption;
use Modules\CorporateConventions\Services\WelfareVoucherService;
use OpenApi\Attributes as OA;

/**
 * Welfare vouchers (IT Art. 51 TUIR, CH Reka / Lunch-Check): redemption at the till and settlement with the providers.
 */
final class WelfareVoucherController extends Controller
{
    use ApiResponse;
    use InteractsWithConventions;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly WelfareVoucherService $vouchers) {}

    #[OA\Get(path: '/api/v1/sales/welfare-vouchers', operationId: 'welfareVouchersList', summary: 'List the redeemed welfare vouchers', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 200, description: 'Redemptions, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', WelfareVoucherRedemption::class);

        return $this->paged($this->vouchers->query($request->validated())->paginate($this->perPage()), WelfareRedemptionResource::class);
    }

    #[OA\Get(path: '/api/v1/sales/welfare-vouchers/summary', operationId: 'welfareVouchersSummary', summary: 'Gross, merchant fee and net receivable by provider and settlement status', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 200, description: 'Summary')])]
    public function summary(): JsonResponse
    {
        Gate::authorize('viewAny', WelfareVoucherRedemption::class);

        return $this->successResponse($this->vouchers->summary(), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/sales/welfare-vouchers/redeem', operationId: 'welfareVouchersRedeem', summary: 'Record a welfare voucher paid at the till with the merchant fee deducted', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 201, description: 'Voucher redeemed')])]
    public function redeem(RedeemVoucherRequest $request, RedeemWelfareVoucherHandler $handler): JsonResponse
    {
        Gate::authorize('create', WelfareVoucherRedemption::class);
        $voucher = $handler->handle(new RedeemWelfareVoucherCommand($request->validated(), $this->tenantId(), $this->userId()));

        return $this->successResponse(new WelfareRedemptionResource($voucher), $this->message('redeemed'), 201);
    }

    #[OA\Post(path: '/api/v1/sales/welfare-vouchers/submit-batch', operationId: 'welfareVouchersSubmitBatch', summary: 'Send pending vouchers of a provider to settlement under a batch reference', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 200, description: 'Vouchers submitted')])]
    public function submitBatch(ReconcileVouchersRequest $request): JsonResponse
    {
        Gate::authorize('bill', WelfareVoucherRedemption::class);
        $count = $this->vouchers->submitBatch(WelfareProvider::from((string) $request->validated('provider')), $request->validated('redemption_ids'), (string) $request->validated('batch_reference'), $this->userId());

        return $this->successResponse(['submitted' => $count, 'batch_reference' => $request->validated('batch_reference')], $this->message('submitted'));
    }

    #[OA\Post(path: '/api/v1/sales/welfare-vouchers/reconcile', operationId: 'welfareVouchersReconcile', summary: 'Reconcile vouchers against the settlement statement or self-invoice of a provider', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 200, description: 'Reconciliation result with the discrepancies')])]
    public function reconcile(ReconcileVouchersRequest $request, ReconcileWelfareVoucherBatchHandler $handler): JsonResponse
    {
        Gate::authorize('bill', WelfareVoucherRedemption::class);
        $result = $handler->handle(new ReconcileWelfareVoucherBatchCommand(
            WelfareProvider::from((string) $request->validated('provider')),
            (string) $request->validated('batch_reference'),
            $request->validated('self_invoice_number'),
            $request->validated('lines'),
            $this->userId(),
        ));

        return $this->successResponse($result, $this->message('reconciled'));
    }
}
