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
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

#[OA\Tag(name: 'Loyalty Cards', description: 'Customer loyalty cards and point/balance transactions')]
class LoyaltyCardController extends Controller
{
    use HandlesLookupCrud {
        index as lookupIndex;
        show as lookupShow;
        destroy as lookupDestroy;
    }

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

    #[OA\Get(
        path: '/api/v1/loyalty-cards',
        summary: 'List loyalty cards',
        description: 'Get paginated list of loyalty cards',
        tags: ['Loyalty Cards'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loyalty cards retrieved'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return $this->lookupIndex($request);
    }

    #[OA\Post(
        path: '/api/v1/loyalty-cards',
        summary: 'Create loyalty card',
        description: 'Issue a new customer loyalty card',
        tags: ['Loyalty Cards'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['loyalty', 'voucher', 'prepaid']),
                    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'card_number', type: 'string', nullable: true),
                    new OA\Property(property: 'currency', type: 'string', nullable: true),
                    new OA\Property(property: 'expires_at', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'initial_balance', type: 'number', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Loyalty card created successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/loyalty-cards/{id}',
        summary: 'Get loyalty card',
        description: 'Retrieve details of a loyalty card',
        tags: ['Loyalty Cards'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loyalty card retrieved'),
            new OA\Response(response: 404, description: 'Loyalty card not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->lookupShow($id);
    }

    #[OA\Put(
        path: '/api/v1/loyalty-cards/{id}',
        summary: 'Update loyalty card',
        description: 'Update loyalty card properties',
        tags: ['Loyalty Cards'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['loyalty', 'voucher', 'prepaid']),
                    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['active', 'blocked']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Loyalty card updated'),
            new OA\Response(response: 404, description: 'Loyalty card not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function update(StoreLoyaltyCardRequest $request, string $id): JsonResponse
    {
        return $this->updateRecord($id, $request->validated());
    }

    #[OA\Delete(
        path: '/api/v1/loyalty-cards/{id}',
        summary: 'Delete loyalty card',
        description: 'Delete a loyalty card',
        tags: ['Loyalty Cards'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loyalty card deleted'),
            new OA\Response(response: 404, description: 'Loyalty card not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        return $this->lookupDestroy($id);
    }

    #[OA\Get(
        path: '/api/v1/loyalty-cards/{id}/transactions',
        summary: 'List card transactions',
        description: 'Get point or credit history for a loyalty card',
        tags: ['Loyalty Cards'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Card transactions retrieved'),
            new OA\Response(response: 404, description: 'Loyalty card not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function transactions(string $id): JsonResponse
    {
        $card = LoyaltyCard::findOrFail($id);

        return $this->successResponse(
            CatalogResource::collection($card->transactions()->latest()->get()),
            __('loyalty.messages.card_transactions')
        );
    }

    #[OA\Post(
        path: '/api/v1/loyalty-cards/{id}/transactions',
        summary: 'Record loyalty transaction',
        description: 'Earn, redeem, top up or adjust the points/balance of a loyalty card',
        tags: ['Loyalty Cards'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'amount'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['earn', 'redeem', 'topup', 'adjust']),
                    new OA\Property(property: 'amount', type: 'number'),
                    new OA\Property(property: 'description', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Transaction recorded'),
            new OA\Response(response: 404, description: 'Loyalty card not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function storeTransaction(StoreLoyaltyTransactionRequest $request, string $id): JsonResponse
    {
        $transaction = $this->applyTransaction->execute(LoyaltyCard::findOrFail($id), $request->validated());

        return $this->successResponse(new CatalogResource($transaction), __('loyalty.messages.transaction_recorded'), Response::HTTP_CREATED);
    }
}
