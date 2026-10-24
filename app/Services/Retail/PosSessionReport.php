<?php

declare(strict_types=1);

namespace App\Services\Retail;

use App\Models\PosSale;
use App\Models\PosSession;

class PosSessionReport
{
    /**
     * Takings summary of a session (X report while open, Z report once closed).
     *
     * @return array<string, mixed>
     */
    public function build(PosSession $session): array
    {
        $sales = PosSale::where('pos_session_id', $session->id)->get();
        $completed = $sales->where('status', 'completed');
        $refunded = $sales->where('status', 'refunded');

        return [
            'session_id' => $session->id,
            'status' => $session->status,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'sales_count' => $completed->count(),
            'refunds_count' => $refunded->count(),
            'total_net' => round((float) $completed->sum('total_net'), 2),
            'total_tax' => round((float) $completed->sum('total_tax'), 2),
            'total_gross' => round((float) $completed->sum('total_gross'), 2),
            'refunded_gross' => round((float) $refunded->sum('total_gross'), 2),
            'by_payment_method' => $completed->groupBy('payment_method')
                ->map(fn ($group): float => round((float) $group->sum('total_gross'), 2))->all(),
            'cash_takings' => round((float) $completed->sum('cash_amount'), 2),
            'card_takings' => round((float) $completed->sum('card_amount'), 2),
            'opening_float' => (float) $session->opening_float,
            'expected_cash' => $session->expected_cash !== null
                ? (float) $session->expected_cash
                : round((float) $session->opening_float + (float) $completed->sum('cash_amount'), 2),
            'closing_cash' => $session->closing_cash !== null ? (float) $session->closing_cash : null,
            'cash_difference' => $session->cash_difference !== null ? (float) $session->cash_difference : null,
        ];
    }
}
