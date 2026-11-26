<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Persistence;

use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Exceptions\LedgerPostingException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\PostingAccountResolver;
use Modules\Treasury\Currency\Domain\Exceptions\CurrencyDomainException;

/**
 * Posts the exchange-difference entries of the currency engine to the General Ledger (Mod 012) and finds their accounts: the
 * accounts chosen in the currency settings, otherwise the default posting accounts of the ledger. A missing account refuses
 * the operation (422): nothing is posted to an invented account.
 */
final readonly class FxLedgerPoster
{
    public const string UNREALIZED_GAIN = 'unrealized_gain';

    public const string UNREALIZED_GAIN_PROVISION = 'unrealized_gain_provision';

    public const string UNREALIZED_LOSS = 'unrealized_loss';

    public const string REALIZED_GAIN = 'realized_gain';

    public const string REALIZED_LOSS = 'realized_loss';

    public function __construct(
        private DoubleEntryPostingServiceInterface $posting,
        private PostingAccountResolver $accounts,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * @param  self::UNREALIZED_*|self::REALIZED_*  $kind
     */
    public function counterAccountId(string $tenantId, EffectiveCurrencySettings $settings, string $kind): string
    {
        $configured = match ($kind) {
            self::UNREALIZED_GAIN => $settings->gainAccountId,
            self::UNREALIZED_LOSS => $settings->lossAccountId,
            self::UNREALIZED_GAIN_PROVISION => $settings->gainProvisionAccountId,
            default => null,
        };

        if ($configured !== null && ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($configured)->exists()) {
            return $configured;
        }

        try {
            $account = match ($kind) {
                self::UNREALIZED_GAIN => $this->accounts->require($tenantId, PostingAccountRole::FxUnrealisedGain),
                self::UNREALIZED_GAIN_PROVISION => $this->accounts->require($tenantId, PostingAccountRole::FxUnrealisedGainProvision),
                self::UNREALIZED_LOSS => $this->accounts->require($tenantId, PostingAccountRole::FxUnrealisedLoss),
                self::REALIZED_GAIN => $this->accounts->account($tenantId, PostingAccountRole::FxRealisedGain) ?? $this->accounts->require($tenantId, PostingAccountRole::FxUnrealisedGain),
                default => $this->accounts->account($tenantId, PostingAccountRole::FxRealisedLoss) ?? $this->accounts->require($tenantId, PostingAccountRole::FxUnrealisedLoss),
            };
        } catch (LedgerPostingException $exception) {
            throw new CurrencyDomainException($exception->getMessage(), 'fx_account_missing', 422);
        }

        return (string) $account->id;
    }

    public function controlAccountId(string $tenantId, bool $receivable): string
    {
        try {
            return (string) $this->accounts->require($tenantId, $receivable ? PostingAccountRole::Receivables : PostingAccountRole::Payables)->id;
        } catch (LedgerPostingException $exception) {
            throw new CurrencyDomainException($exception->getMessage(), 'fx_account_missing', 422);
        }
    }

    /**
     * @param  list<array{account_id: string, debit_amount: string, credit_amount: string, description: string, currency: string}>  $lines
     */
    public function post(string $tenantId, string $date, string $sourceType, string $sourceId, string $description, array $lines, ?string $actorId): JournalEntry
    {
        return $this->posting->createAndPost([
            'tenant_id' => $tenantId,
            'fiscal_period_id' => (string) $this->periods->forDate($tenantId, $date)->id,
            'posting_date' => $date,
            'document_date' => $date,
            'journal_type' => 'adjusting',
            'description' => $description,
            'source_document_type' => $sourceType,
            'source_document_id' => $sourceId,
            'bank_balance_managed' => true,
            'lines' => $lines,
        ], $actorId);
    }

    public function reverse(string $journalEntryId, string $date, string $reason, ?string $actorId): JournalEntry
    {
        return $this->posting->reverseJournal($journalEntryId, $date, $reason, $actorId, true);
    }
}
