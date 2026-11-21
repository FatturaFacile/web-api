<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\Quoting\Application\Commands\RejectQuoteCommand;
use Modules\Quoting\Domain\Enums\QuoteStatus;
use Modules\Quoting\Domain\Events\QuoteRejected;
use Modules\Quoting\Domain\Exceptions\QuoteException;
use Modules\Quoting\Infrastructure\Services\PublicQuoteGate;
use Modules\Quoting\Infrastructure\Services\QuoteAuditTrail;

final class RejectQuoteHandler
{
    public function __construct(private readonly PublicQuoteGate $gate, private readonly QuoteAuditTrail $audit) {}

    public function handle(RejectQuoteCommand $command): void
    {
        $reason = trim($command->reason);
        if ($reason === '') {
            throw new QuoteException('A rejection reason is mandatory.');
        }

        $quote = $this->gate->resolve($command->token, $command->session);

        DB::transaction(function () use ($quote, $reason, $command): void {
            $quote->forceFill(['status' => QuoteStatus::Rejected->value, 'rejected_at' => now(), 'rejection_reason' => $reason, 'public_token_hash' => null])->save();
            $this->audit->record($quote, 'rejected', 'customer', $command->ip, ['reason' => $reason]);
        });

        event(new QuoteRejected($quote->id, $reason));
    }
}
