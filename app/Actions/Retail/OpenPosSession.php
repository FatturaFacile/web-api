<?php

declare(strict_types=1);

namespace App\Actions\Retail;

use App\Models\PosSession;
use App\Models\PosTerminal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenPosSession
{
    /**
     * Open a cash session on a terminal; a terminal can have only one open session at a time.
     */
    public function execute(string $terminalId, float $openingFloat): PosSession
    {
        $terminal = PosTerminal::findOrFail($terminalId);

        if (! $terminal->is_active) {
            throw ValidationException::withMessages(['pos_terminal_id' => __('pos.terminal_inactive')]);
        }

        return DB::transaction(function () use ($terminal, $openingFloat): PosSession {
            if (PosSession::where('pos_terminal_id', $terminal->id)->where('status', 'open')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['pos_terminal_id' => __('pos.terminal_busy')]);
            }

            return PosSession::create([
                'pos_terminal_id' => $terminal->id,
                'user_id' => Auth::id(),
                'opened_at' => now(),
                'opening_float' => $openingFloat,
                'total_sales' => 0,
                'status' => 'open',
            ]);
        });
    }
}
