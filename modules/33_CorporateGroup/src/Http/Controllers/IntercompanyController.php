<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\CorporateGroup\Models\IntercompanyTransaction;
use Modules\CorporateGroup\Services\IntercompanyService;
use Modules\CorporateGroup\Support\CorporatePermissions as P;

final class IntercompanyController extends CorporateController
{
    public function __construct(private readonly IntercompanyService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany($request, P::INTERCOMPANY_VIEW, null);
        $ids = P::accessibleCompanyIds($request->user());

        $transactions = IntercompanyTransaction::query()
            ->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('seller_company_id', $ids)->orWhereIn('buyer_company_id', $ids)))
            ->orderByDesc('transaction_date')
            ->get();

        return response()->json(['data' => $transactions]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $transaction = IntercompanyTransaction::query()->with('documents')->findOrFail($id);
        $this->authorizeSide($request, $transaction, P::INTERCOMPANY_VIEW);

        // Each company only sees its own documents.
        $user = $request->user();
        $visible = $transaction->documents->filter(fn ($doc): bool => P::canAccessCompany($user, (string) $doc->company_id))->values();

        return response()->json(['data' => array_merge($transaction->toArray(), ['documents' => $visible])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seller_company_id' => ['required', 'uuid', 'different:buyer_company_id'],
            'buyer_company_id' => ['required', 'uuid'],
            'transaction_type' => ['nullable', Rule::in(['goods', 'services', 'recharge'])],
            'transaction_date' => ['required', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'net_amount' => ['required', 'numeric', 'gt:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:60'],
            'lines' => ['nullable', 'array'],
            'lines.*.description' => ['required_with:lines', 'string'],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required_with:lines', 'numeric', 'min:0'],
        ]);

        // The operation books on both companies: the user must be allowed to manage intercompany on each of them.
        $this->authorizeCompany($request, P::INTERCOMPANY_MANAGE, $data['seller_company_id']);
        $this->authorizeCompany($request, P::INTERCOMPANY_MANAGE, $data['buyer_company_id']);

        return $this->guard(function () use ($request, $data): JsonResponse {
            $transaction = $this->service->record($this->tenantId($request), $data, (string) $request->user()?->id);

            return response()->json(['data' => $transaction], 201);
        });
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $transaction = IntercompanyTransaction::query()->findOrFail($id);
        $this->authorizeSide($request, $transaction, P::INTERCOMPANY_MANAGE);

        $data = $request->validate(['reversal_date' => ['nullable', 'date'], 'reason' => ['nullable', 'string', 'max:255']]);

        return $this->guard(fn (): JsonResponse => response()->json([
            'data' => $this->service->cancel($transaction, $data['reversal_date'] ?? null, $data['reason'] ?? null, (string) $request->user()?->id),
        ]));
    }

    /** The user needs the permission on the seller or on the buyer to look at a transaction; on both to change it. */
    private function authorizeSide(Request $request, IntercompanyTransaction $transaction, string $permission): void
    {
        $seller = P::allows($request->user(), $permission, (string) $transaction->seller_company_id);
        $buyer = P::allows($request->user(), $permission, (string) $transaction->buyer_company_id);

        abort_unless($permission === P::INTERCOMPANY_VIEW ? ($seller || $buyer) : ($seller && $buyer), 403, 'Missing permission: '.$permission);
    }
}
