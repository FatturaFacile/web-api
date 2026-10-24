<?php

declare(strict_types=1);

namespace App\Actions\Retail;

use App\Models\PosSale;
use App\Models\PosSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClosePosSession
{
    /**
     * Close a session, comparing the counted cash with opening float plus cash takings of completed sales.
     */
    public function execute(PosSession $session, float $closingCash): PosSession
    {
        return DB::transaction(function () use ($session, $closingCash): PosSession {
            $session = PosSession::lockForUpdate()->findOrFail($session->id);

            if ($session->status !== 'open') {
                throw ValidationException::withMessages(['pos_session_id' => __('pos.session_closed')]);
            }

            $expected = round((float) $session->opening_float + (float) PosSale::where('pos_session_id', $session->id)->where('status', 'completed')->sum('cash_amount'), 2);

            $session->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closing_cash' => $closingCash,
                'expected_cash' => $expected,
                'cash_difference' => round($closingCash - $expected, 2),
            ]);

            return $session;
        });
    }
}
