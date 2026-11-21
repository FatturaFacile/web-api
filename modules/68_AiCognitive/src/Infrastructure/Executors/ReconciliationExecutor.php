<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Executors;

use App\Models\BankTransaction;
use App\Models\Invoice;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;
use Modules\AiCognitive\Domain\Exceptions\AiCognitiveException;

/**
 * Links a bank transaction to the invoice a reviewer (or a very confident match) chose. Only the link is written: the
 * registration of the payment on the invoice stays an accountant action, so a partial or grouped payment is never closed
 * by a guess.
 */
final class ReconciliationExecutor implements SuggestionExecutorInterface
{
    public function supports(SuggestionKind $kind): bool
    {
        return $kind === SuggestionKind::ReconciliationMatch;
    }

    public function execute(string $tenantId, ?string $userId, SuggestionKind $kind, array $payload): array
    {
        $transaction = BankTransaction::query()->where('tenant_id', $tenantId)->find((string) ($payload['bank_transaction_id'] ?? ''));
        $invoice = Invoice::query()->where('tenant_id', $tenantId)->find((string) ($payload['invoice_id'] ?? ''));

        if (! $transaction instanceof BankTransaction || ! $invoice instanceof Invoice) {
            throw new AiCognitiveException('The bank transaction or the invoice of the match no longer exists.');
        }

        if ($transaction->status !== 'unmatched') {
            throw new AiCognitiveException('The bank transaction was already matched.');
        }

        $transaction->update(['matched_invoice_id' => $invoice->id, 'status' => 'matched']);

        return ['type' => 'bank_transactions', 'id' => (string) $transaction->id];
    }
}
