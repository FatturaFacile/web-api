<?php

declare(strict_types=1);

namespace App\Actions\Portal;

use App\Enums\QuoteStatus;
use App\Events\Portal\QuoteApprovedByCustomer;
use App\Jobs\Portal\NotifyQuoteStatusChangedJob;
use App\Models\PortalAccessToken;
use App\Models\Quote;
use App\Services\Portal\PortalTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveQuoteAction
{
    public function __construct(private readonly PortalTokenService $tokenService) {}

    /**
     * Customer approves quote via magic link.
     */
    public function execute(Quote $quote, PortalAccessToken $token, string $signerName, ?string $signatureData = null, ?string $notes = null): Quote
    {
        return DB::transaction(function () use ($quote, $token, $signerName, $signatureData, $notes): Quote {
            $quote = Quote::lockForUpdate()->findOrFail($quote->id);

            if ($quote->contact_id !== $token->contact_id || $quote->tenant_id !== $token->tenant_id) {
                throw ValidationException::withMessages(['quote' => __('portal_errors.quote.unauthorized')]);
            }

            if ($quote->signed_at !== null || $quote->status === QuoteStatus::Accepted->value) {
                throw ValidationException::withMessages(['quote' => __('portal_errors.quote.already_accepted')]);
            }

            if (! QuoteStatus::allows($quote->status, QuoteStatus::Accepted)) {
                throw ValidationException::withMessages(['quote' => __('portal_errors.quote.not_acceptable')]);
            }

            $quote->update([
                'status' => QuoteStatus::Accepted->value,
                'signed_by' => $signerName,
                'signature_data' => $signatureData,
                'signed_at' => now(),
                'notes' => $notes ? trim(($quote->notes ? $quote->notes."\n" : '')."Customer Acceptance Note: {$notes}") : $quote->notes,
            ]);

            $this->tokenService->logActivity(
                token: $token,
                action: 'quote_approved',
                subjectType: 'quote',
                subjectId: $quote->id,
                metadata: [
                    'quote_number' => $quote->quote_number,
                    'signer_name' => $signerName,
                    'has_signature' => ! empty($signatureData),
                ]
            );

            if ($token->is_single_use) {
                $token->markUsed();
            }

            event(new QuoteApprovedByCustomer($quote, $token, $signerName, $notes));

            NotifyQuoteStatusChangedJob::dispatch($quote, 'accepted', $signerName, $notes)->afterCommit();

            return $quote->load(['items', 'contact']);
        });
    }
}
