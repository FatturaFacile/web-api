<?php

declare(strict_types=1);

namespace App\Actions\Portal;

use App\Events\Portal\QuoteRejectedByCustomer;
use App\Jobs\Portal\NotifyQuoteStatusChangedJob;
use App\Models\PortalAccessToken;
use App\Models\Quote;
use App\Services\Portal\PortalTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectQuoteAction
{
    public function __construct(private readonly PortalTokenService $tokenService) {}

    /**
     * Customer rejects quote via magic link.
     */
    public function execute(Quote $quote, PortalAccessToken $token, ?string $reason = null): Quote
    {
        return DB::transaction(function () use ($quote, $token, $reason): Quote {
            $quote = Quote::lockForUpdate()->findOrFail($quote->id);

            if ($quote->contact_id !== $token->contact_id || $quote->tenant_id !== $token->tenant_id) {
                throw ValidationException::withMessages(['quote' => 'Unauthorized quote access.']);
            }

            if ($quote->status === 'accepted') {
                throw ValidationException::withMessages(['quote' => 'Accepted quotes cannot be rejected.']);
            }

            $quote->update([
                'status' => 'rejected',
                'notes' => $reason ? trim(($quote->notes ? $quote->notes."\n" : '')."Customer Rejection Reason: {$reason}") : $quote->notes,
            ]);

            $this->tokenService->logActivity(
                token: $token,
                action: 'quote_rejected',
                subjectType: 'quote',
                subjectId: $quote->id,
                metadata: [
                    'quote_number' => $quote->quote_number,
                    'reason' => $reason,
                ]
            );

            if ($token->is_single_use) {
                $token->markUsed();
            }

            event(new QuoteRejectedByCustomer($quote, $token, $reason));

            NotifyQuoteStatusChangedJob::dispatch($quote, 'rejected', $token->contact->name ?? 'Customer', $reason)->afterCommit();

            return $quote->load(['items', 'contact']);
        });
    }
}
