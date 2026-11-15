<?php

declare(strict_types=1);

namespace App\Actions\Retail;

use App\Models\PosSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\FiscalRetail\Services\CashDrawerService;
use Modules\FiscalRetail\Services\SessionReportService;

class ClosePosSession
{
    /**
     * Close a session, comparing the counted cash with what the drawer should hold (opening float, cash takings of
     * completed sales, pay-ins, pay-outs, safe drops and cash refunds of returns), and issue its Z report.
     */
    public function execute(PosSession $session, float $closingCash): PosSession
    {
        return DB::transaction(function () use ($session, $closingCash): PosSession {
            $session = PosSession::lockForUpdate()->findOrFail($session->id);

            if ($session->status !== 'open') {
                throw ValidationException::withMessages(['pos_session_id' => __('pos.session_closed')]);
            }

            $expected = round((float) app(CashDrawerService::class)->expectedCash($session), 2);

            $session->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closing_cash' => $closingCash,
                'expected_cash' => $expected,
                'cash_difference' => round($closingCash - $expected, 2),
            ]);

            app(SessionReportService::class)->z($session->refresh(), $session->user_id !== null ? (string) $session->user_id : null);

            return $session;
        });
    }
}
