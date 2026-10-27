<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Loyalty\ApplyLoyaltyTransaction;
use App\Http\Controllers\Api\V1\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StoreLoyaltyCardRequest;
use App\Http\Requests\Operations\StoreLoyaltyTransactionRequest;
use App\Http\Resources\CatalogResource;
use App\Models\LoyaltyCard;
use App\Services\Sales\DocumentNumberingService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class LoyaltyCardController extends Controller
{
    use HandlesLookupCrud;

    public function __construct(
        private readonly ApplyLoyaltyTransaction $applyTransaction,
        private readonly DocumentNumberingService $numbering,
    ) {}

    protected function modelClass(): string
    {
        return LoyaltyCard::class;
    }

    protected function label(): string
    {
        return __('api.labels.loyalty_card');
    }

    public function store(StoreLoyaltyCardRequest $request): JsonResponse
    {
        $data = $request->validated();
        $initial = (float) ($data['initial_balance'] ?? 0);
        unset($data['initial_balance']);

        $card = LoyaltyCard::resolveConnection()->transaction(function () use ($data, $initial): LoyaltyCard {
            $data['card_number'] ??= $this->numbering->next('loyalty_card')['number'];
            $card = LoyaltyCard::create($data);

            if ($initial > 0) {
                $this->applyTransaction->execute($card, ['type' => 'topup', 'amount' => $initial, 'description' => __('loyalty.initial_balance')]);
            }

            return $card->refresh();
        });

        return $this->successResponse(new CatalogResource($card), __('loyalty.messages.card_created'), Response::HTTP_CREATED);
    }

    public function update(StoreLoyaltyCardRequest $request, string $id): JsonResponse
    {
        return $this->updateRecord($id, $request->validated());
    }

    public function transactions(string $id): JsonResponse
    {
        $card = LoyaltyCard::findOrFail($id);

        return $this->successResponse(
            CatalogResource::collection($card->transactions()->latest()->get()),
            __('loyalty.messages.card_transactions')
        );
    }

    public function storeTransaction(StoreLoyaltyTransactionRequest $request, string $id): JsonResponse
    {
        $transaction = $this->applyTransaction->execute(LoyaltyCard::findOrFail($id), $request->validated());

        return $this->successResponse(new CatalogResource($transaction), __('loyalty.messages.transaction_recorded'), Response::HTTP_CREATED);
    }
}
