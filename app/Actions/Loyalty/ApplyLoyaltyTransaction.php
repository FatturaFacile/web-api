<?php

declare(strict_types=1);

namespace App\Actions\Loyalty;

use App\Models\LoyaltyCard;
use App\Models\LoyaltyTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyLoyaltyTransaction
{
    /**
     * Apply a balance movement under a row lock. Redemptions and adjustments can never take the balance below zero;
     * blocked or expired cards reject every movement.
     *
     * @param  array{type: string, amount: float|int|string, description?: ?string, invoice_id?: ?string}  $data
     */
    public function execute(LoyaltyCard $card, array $data): LoyaltyTransaction
    {
        $expired = false;

        $transaction = DB::transaction(function () use ($card, $data, &$expired): ?LoyaltyTransaction {
            $card = LoyaltyCard::lockForUpdate()->findOrFail($card->id);

            if ($card->status !== 'active') {
                throw ValidationException::withMessages(['card' => __('loyalty.card_inactive', ['status' => $card->status])]);
            }

            if ($card->expires_at !== null && $card->expires_at->lt(today())) {
                $expired = true;

                return null;
            }

            $amount = round(abs((float) $data['amount']), 2);
            $signed = match ($data['type']) {
                'redeem' => -$amount,
                'adjust' => round((float) $data['amount'], 2),
                default => $amount, // earn, topup
            };

            $balance = round((float) $card->balance + $signed, 2);

            if ($balance < 0) {
                throw ValidationException::withMessages(['amount' => __('loyalty.insufficient_balance')]);
            }

            $card->update(['balance' => $balance]);

            return $card->transactions()->create([
                'tenant_id' => $card->tenant_id,
                'invoice_id' => $data['invoice_id'] ?? null,
                'type' => $data['type'],
                'amount' => $signed,
                'balance_after' => $balance,
                'description' => $data['description'] ?? null,
            ]);
        });

        if ($expired) {
            // Persisted outside the transaction so the status change survives the validation error.
            LoyaltyCard::whereKey($card->id)->update(['status' => 'expired']);

            throw ValidationException::withMessages(['card' => __('loyalty.card_expired')]);
        }

        return $transaction;
    }
}
