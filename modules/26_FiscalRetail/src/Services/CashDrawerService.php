<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Services;

use App\Models\PosSale;
use App\Models\PosSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\FiscalRetail\Models\RetailCashMovement;
use Modules\FiscalRetail\Models\RetailReturn;
use Modules\FiscalRetail\Models\RetailSalePayment;

/**
 * The cash drawer of a POS session: pay-ins, pay-outs and safe drops while it is open, and the cash that should be in
 * it. Expected cash is the opening float, plus the cash of completed sales and pay-ins, minus pay-outs, safe drops
 * and the cash given back for returns.
 */
final class CashDrawerService
{
    /**
     * @param  array{type: string, amount: numeric-string|float|int, reason?: string|null}  $data
     */
    public function record(PosSession $session, array $data, ?string $userId): RetailCashMovement
    {
        return DB::transaction(function () use ($session, $data, $userId): RetailCashMovement {
            $session = PosSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($session->status !== 'open') {
                throw ValidationException::withMessages(['pos_session_id' => __('pos.session_closed')]);
            }

            $amount = bcadd((string) $data['amount'], '0', 2);

            if ($data['type'] !== 'pay_in' && bccomp($amount, $this->expectedCash($session), 2) > 0) {
                throw ValidationException::withMessages(['amount' => __('pos.drawer_cash_insufficient')]);
            }

            return RetailCashMovement::create([
                'tenant_id' => $session->tenant_id,
                'pos_session_id' => $session->id,
                'type' => $data['type'],
                'amount' => $amount,
                'reason' => $data['reason'] ?? null,
                'user_id' => $userId,
            ]);
        });
    }

    /**
     * @return array{opening_float: string, cash_sales: string, pay_in: string, pay_out: string, safe_drop: string, cash_refunds: string, expected_cash: string}
     */
    public function summary(PosSession $session): array
    {
        $movement = static fn (string $type): string => bcadd((string) RetailCashMovement::query()->where('pos_session_id', $session->id)->where('type', $type)->sum('amount'), '0', 2);
        $cashSales = bcadd((string) PosSale::query()->where('pos_session_id', $session->id)->where('status', 'completed')->sum('cash_amount'), '0', 2);
        $cashRefunds = bcadd((string) RetailReturn::query()->where('pos_session_id', $session->id)->sum('cash_refunded'), '0', 2);
        $openingFloat = bcadd((string) $session->opening_float, '0', 2);
        $expected = bcsub(bcsub(bcsub(bcadd(bcadd($openingFloat, $cashSales, 2), $movement('pay_in'), 2), $movement('pay_out'), 2), $movement('safe_drop'), 2), $cashRefunds, 2);

        return [
            'opening_float' => $openingFloat,
            'cash_sales' => $cashSales,
            'pay_in' => $movement('pay_in'),
            'pay_out' => $movement('pay_out'),
            'safe_drop' => $movement('safe_drop'),
            'cash_refunds' => $cashRefunds,
            'expected_cash' => $expected,
        ];
    }

    public function expectedCash(PosSession $session): string
    {
        return $this->summary($session)['expected_cash'];
    }

    /**
     * Totals of the payments of the completed sales of a session by method. Sales made before payments were recorded
     * per method fall back on their cash and card amounts.
     *
     * @return array<string, string>
     */
    public function paymentsByMethod(PosSession $session): array
    {
        $sales = PosSale::query()->where('pos_session_id', $session->id)->where('status', 'completed')->get(['id', 'cash_amount', 'card_amount']);
        $recorded = RetailSalePayment::query()->whereIn('pos_sale_id', $sales->pluck('id'))->get()->groupBy('pos_sale_id');
        $totals = [];

        foreach ($sales as $sale) {
            $rows = $recorded->get($sale->id);

            if ($rows === null) {
                $rows = collect([
                    (object) ['method' => 'cash', 'amount' => (string) $sale->cash_amount],
                    (object) ['method' => 'card', 'amount' => (string) $sale->card_amount],
                ]);
            }

            foreach ($rows as $row) {
                if (bccomp((string) $row->amount, '0', 2) > 0) {
                    $totals[$row->method] = bcadd($totals[$row->method] ?? '0.00', (string) $row->amount, 2);
                }
            }
        }

        ksort($totals);

        return $totals;
    }
}
