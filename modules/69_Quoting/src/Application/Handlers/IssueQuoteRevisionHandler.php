<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Quoting\Application\Commands\IssueQuoteRevisionCommand;
use Modules\Quoting\Domain\Enums\QuoteStatus;
use Modules\Quoting\Domain\Events\QuoteIssued;
use Modules\Quoting\Domain\Exceptions\QuoteException;
use Modules\Quoting\Domain\ValueObjects\DocumentHash;
use Modules\Quoting\Infrastructure\Models\CpqQuote;
use Modules\Quoting\Infrastructure\Services\PublicQuoteGate;
use Modules\Quoting\Infrastructure\Services\QuoteAuditTrail;
use Modules\Quoting\Infrastructure\Services\QuoteSnapshotBuilder;

/**
 * Freezes a draft revision (snapshot + SHA-256), supersedes the earlier open revisions of the same quote and publishes the
 * unguessable link (plus an optional PIN). A margin below the guardrail parks the quote in PendingApproval until someone with the
 * override issues it.
 *
 * @phpstan-type Issued array{quote: CpqQuote, portal_token: string|null, pin: string|null}
 */
final class IssueQuoteRevisionHandler
{
    public function __construct(private readonly QuoteSnapshotBuilder $snapshots, private readonly QuoteAuditTrail $audit) {}

    /**
     * @return Issued
     */
    public function handle(IssueQuoteRevisionCommand $command): array
    {
        $result = DB::transaction(function () use ($command): array {
            $quote = CpqQuote::query()->whereKey($command->quoteId)->lockForUpdate()->firstOrFail();
            $quote->assertEditable();

            if ($quote->lines()->count() === 0) {
                throw new QuoteException('A quote without lines cannot be issued.');
            }

            $snapshot = $this->snapshots->snapshot($quote);
            $marginBelowGuardrail = $quote->min_margin_percent !== null && $snapshot['totals']['margin_percent'] !== null
                && bccomp((string) $snapshot['totals']['margin_percent'], (string) $quote->min_margin_percent, 4) < 0;

            if ($marginBelowGuardrail && ! $command->overrideMarginGuardrail) {
                $quote->forceFill(['status' => QuoteStatus::PendingApproval->value, 'requires_approval' => true, 'updated_by' => $command->userId])->save();
                $this->audit->record($quote, 'approval_requested', $command->userId, null, ['margin_percent' => $snapshot['totals']['margin_percent']]);

                return ['quote' => $quote, 'portal_token' => null, 'pin' => null];
            }

            $days = $command->validityDays ?? (int) config('quoting.default_validity_days', 30);
            $validUntil = $quote->valid_until ?? now()->addDays($days);
            $token = PublicQuoteGate::newToken();
            $pin = $command->usePin ? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) : null;

            // The earlier open revisions of the same quote stop being offered and lose their link.
            CpqQuote::query()->where('root_quote_id', $quote->root_quote_id)->whereKeyNot($quote->id)
                ->whereIn('status', [QuoteStatus::Sent->value, QuoteStatus::Viewed->value, QuoteStatus::Negotiating->value])
                ->get()->each(function (CpqQuote $old) use ($quote): void {
                    $old->forceFill(['status' => QuoteStatus::Superseded->value, 'public_token_hash' => null, 'public_pin_hash' => null])->save();
                    $this->audit->record($old, 'superseded', null, null, ['by' => $quote->quote_number]);
                });

            $quote->forceFill([
                'frozen_snapshot' => $snapshot,
                'snapshot_hash' => DocumentHash::ofPayload($snapshot)->value,
                'valid_until' => $validUntil->toDateString(),
                'public_token_hash' => PublicQuoteGate::hashToken($token),
                'public_pin_hash' => $pin === null ? null : Hash::make($pin),
                'public_expires_at' => $validUntil->copy()->endOfDay(),
                'issued_at' => now(),
                'requires_approval' => false,
                'status' => QuoteStatus::Sent->value,
                'updated_by' => $command->userId,
            ])->save();

            $this->audit->record($quote, 'sent', $command->userId, null, ['snapshot_hash' => $quote->snapshot_hash, 'margin_override' => $marginBelowGuardrail]);

            return ['quote' => $quote, 'portal_token' => $token, 'pin' => $pin];
        });

        if ($result['portal_token'] !== null) {
            event(new QuoteIssued($result['quote']->id, $result['quote']->customer_email, $result['quote']->quote_number, rtrim((string) config('quoting.portal_url'), '/').'/'.$result['portal_token'], $result['pin']));
        }

        return $result;
    }
}
