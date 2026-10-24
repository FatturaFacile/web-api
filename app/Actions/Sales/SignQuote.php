<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\Quote;
use App\Services\Sales\DocumentEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SignQuote
{
    public function __construct(private readonly DocumentEventRecorder $events) {}

    /**
     * Record the customer's signature on a quote and mark it as accepted. A quote can be signed only once.
     */
    public function execute(Quote $quote, string $signedBy, string $signatureBase64): Quote
    {
        return DB::transaction(function () use ($quote, $signedBy, $signatureBase64): Quote {
            $quote = Quote::lockForUpdate()->findOrFail($quote->id);

            if ($quote->signed_at !== null) {
                throw ValidationException::withMessages(['quote' => __('sales.quote_already_signed')]);
            }

            if (in_array($quote->status, ['rejected', 'expired'], true)) {
                throw ValidationException::withMessages(['quote' => __('sales.quote_not_signable')]);
            }

            $quote->update([
                'signature_data' => $signatureBase64,
                'signed_by' => $signedBy,
                'signed_at' => now(),
                'status' => 'accepted',
            ]);

            $this->events->record($quote, 'signed', ['signed_by' => $signedBy]);

            return $quote;
        });
    }
}
