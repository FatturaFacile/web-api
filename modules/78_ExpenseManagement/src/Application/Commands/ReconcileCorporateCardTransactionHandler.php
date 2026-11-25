<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

use Modules\ExpenseManagement\Domain\Enums\ReconciliationStatus;
use Modules\ExpenseManagement\Exceptions\ExpenseException;
use Modules\ExpenseManagement\Models\CorporateCardTransaction;
use Modules\ExpenseManagement\Services\CardReconciliationService;

final class ReconcileCorporateCardTransactionHandler
{
    public function __construct(private readonly CardReconciliationService $cards) {}

    public function handle(ReconcileCorporateCardTransactionCommand $command): CorporateCardTransaction
    {
        $transaction = CorporateCardTransaction::query()->findOrFail($command->transactionId);

        return match ($command->action) {
            'match' => $this->cards->match($transaction, $command->matches, $command->userId),
            'unmatch' => $this->cards->unmatch($transaction, $command->userId),
            'ignore' => $this->cards->mark($transaction, ReconciliationStatus::Ignored),
            'dispute' => $this->cards->mark($transaction, ReconciliationStatus::Disputed),
            default => throw new ExpenseException('invalid_transition', 409),
        };
    }
}
