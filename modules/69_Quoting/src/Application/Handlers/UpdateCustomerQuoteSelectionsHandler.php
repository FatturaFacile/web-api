<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\Quoting\Application\Commands\UpdateCustomerQuoteSelectionsCommand;
use Modules\Quoting\Domain\Enums\LineSelectionMode;
use Modules\Quoting\Domain\Enums\QuoteStatus;
use Modules\Quoting\Domain\Events\QuoteOptionsUpdatedByCustomer;
use Modules\Quoting\Domain\Exceptions\QuoteException;
use Modules\Quoting\Domain\Services\QuotePricingCalculator;
use Modules\Quoting\Infrastructure\Services\PublicQuoteGate;
use Modules\Quoting\Infrastructure\Services\QuoteAuditTrail;
use Modules\Quoting\Infrastructure\Services\QuoteSnapshotBuilder;

/**
 * Persists what the customer toggled (optional lines, alternative picks) and returns the recalculated proposal. Only the
 * lines of the frozen snapshot that are optional or alternative can be chosen; a mandatory line can never be switched off.
 */
final class UpdateCustomerQuoteSelectionsHandler
{
    public function __construct(
        private readonly PublicQuoteGate $gate,
        private readonly QuoteSnapshotBuilder $snapshots,
        private readonly QuotePricingCalculator $calculator,
        private readonly QuoteAuditTrail $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(UpdateCustomerQuoteSelectionsCommand $command): array
    {
        $quote = $this->gate->resolve($command->token, $command->session);
        $lines = (array) ($quote->frozen_snapshot['lines'] ?? []);
        $selectable = [];
        foreach ($lines as $line) {
            $selectable[(string) $line['id']] = $line;
        }

        foreach (array_keys($command->selections) as $lineId) {
            $mode = isset($selectable[$lineId]) ? LineSelectionMode::from((string) $selectable[$lineId]['selection_mode']) : null;
            if ($mode === null || $mode === LineSelectionMode::Mandatory) {
                throw new QuoteException("Line [{$lineId}] cannot be changed by the customer.");
            }
        }

        // Normalise: every optional line gets its explicit flag and every alternative group exactly one chosen line.
        $merged = [];
        foreach ($command->selections as $lineId => $on) {
            $merged[(string) $lineId] = (bool) $on;
        }
        $included = $this->calculator->resolveIncluded($lines, $merged);
        $normalised = [];
        foreach ($lines as $line) {
            if ($line['selection_mode'] !== LineSelectionMode::Mandatory->value) {
                $normalised[(string) $line['id']] = $included[(string) $line['id']] ?? false;
            }
        }

        $view = DB::transaction(function () use ($quote, $normalised, $command): array {
            $quote->forceFill(['customer_selections' => $normalised])->save();
            if ($quote->status !== QuoteStatus::Negotiating) {
                $quote->forceFill(['status' => QuoteStatus::Negotiating->value])->save();
            }

            $view = $this->snapshots->publicView($quote, $normalised);
            $quote->forceFill(['total_net' => $view['totals']['net'], 'total_tax' => $view['totals']['tax'], 'total_gross' => $view['totals']['payable']])->save();
            $this->audit->record($quote, 'option_toggled', 'customer', $command->ip, ['selections' => $normalised, 'total' => $view['totals']['payable']]);

            return $view;
        });

        event(new QuoteOptionsUpdatedByCustomer($quote->id, $normalised));

        return $view;
    }
}
