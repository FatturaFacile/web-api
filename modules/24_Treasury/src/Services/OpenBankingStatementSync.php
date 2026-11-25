<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use App\Actions\Banking\ImportBankStatement;
use App\DTOs\Banking\ParsedStatement;
use App\DTOs\Banking\ParsedStatementLine;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Exceptions\OpenBankingUnavailableException;
use Modules\Treasury\Models\OpenBankingConsent;
use Modules\Treasury\Models\OpenBankingSyncRun;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Pulls balance and booked movements of a consented account from the PSD2 / bLink provider and stores them as a bank
 * statement, exactly like an uploaded file: movements already imported (same bank reference and amount) are skipped, so
 * running the same period twice, or overlapping periods, never duplicates a line. Every run is recorded, also when the
 * provider fails.
 */
final readonly class OpenBankingStatementSync
{
    public function __construct(
        private OpenBankingService $openBanking,
        private ImportBankStatement $import,
    ) {}

    /**
     * @throws OpenBankingUnavailableException when the provider cannot be reached or refuses (the failed run is kept)
     * @throws ConflictHttpException when the consent is no longer active
     */
    public function sync(OpenBankingConsent $consent, ?string $from, ?string $to, ?string $userId, bool $autoReconcile = true): OpenBankingSyncRun
    {
        if (! $consent->isActive()) {
            throw new ConflictHttpException(__('treasury_errors.open_banking.consent_inactive'));
        }

        $to ??= now()->toDateString();
        $from ??= CarbonImmutable::parse($to)->subDays((int) config('open_banking.sync_lookback_days', 14))->toDateString();

        $run = OpenBankingSyncRun::create([
            'tenant_id' => $consent->tenant_id,
            'consent_id' => $consent->id,
            'driver' => $consent->driver,
            'status' => OpenBankingSyncRun::RUNNING,
            'from_date' => $from,
            'to_date' => $to,
            'started_at' => now(),
            'triggered_by' => $userId,
        ]);

        try {
            $information = $this->openBanking->accountInformation($consent, $from, $to);
            $run->fetched = count($information['transactions']);

            if ($information['transactions'] === []) {
                return $this->finish($run, OpenBankingSyncRun::COMPLETED);
            }

            $parsed = $this->statement($consent, $information, $to);
            $hash = hash('sha256', json_encode([$consent->iban, $from, $to, $information['transactions']], JSON_THROW_ON_ERROR));

            try {
                $result = $this->import->executeParsed($parsed, $hash, $autoReconcile);
            } catch (ValidationException) {
                // The very same payload was imported before: nothing new.
                $run->duplicates = $run->fetched;

                return $this->finish($run, OpenBankingSyncRun::COMPLETED);
            }

            $statement = $result['statement'];

            if ($statement->lines()->count() === 0) {
                $statement->delete();
            } else {
                $run->bank_statement_id = $statement->id;
            }

            $run->imported = $run->fetched - $result['duplicates'];
            $run->duplicates = $result['duplicates'];

            return $this->finish($run, OpenBankingSyncRun::COMPLETED);
        } catch (Throwable $e) {
            $run->error = mb_substr($e->getMessage(), 0, 1000);
            $this->finish($run, OpenBankingSyncRun::FAILED);

            throw $e;
        }
    }

    /**
     * @param  array{balance: array{iban: string, currency: string, balance: string}, transactions: list<array<string, mixed>>}  $information
     */
    private function statement(OpenBankingConsent $consent, array $information, string $to): ParsedStatement
    {
        $currency = strtoupper((string) $information['balance']['currency']);
        $lines = [];

        foreach ($information['transactions'] as $row) {
            $amount = (string) $row['amount'];
            $date = CarbonImmutable::parse((string) $row['booked_at']);
            $description = (string) ($row['description'] ?? '');
            $lines[] = new ParsedStatementLine(
                bookingDate: $date,
                valueDate: $date,
                amount: (float) ltrim($amount, '-'),
                feeAmount: 0.0,
                direction: bccomp($amount, '0', 2) < 0 ? ParsedStatementLine::DEBIT : ParsedStatementLine::CREDIT,
                reference: ($row['reference'] ?? null) ?: null,
                qrReference: $this->structuredReference((string) ($row['reference'] ?? '').' '.$description),
                description: $description !== '' ? $description : null,
                counterpartName: ($row['counterpart_name'] ?? null) ?: null,
                counterpartIban: isset($row['counterpart_iban']) ? strtoupper((string) preg_replace('/\s+/', '', (string) $row['counterpart_iban'])) : null,
                entryReference: (string) $row['external_id'],
            );
        }

        return new ParsedStatement(ParsedStatement::FORMAT_OPEN_BANKING, (string) $consent->iban, CarbonImmutable::parse($to), null, (float) $information['balance']['balance'], $currency, $lines);
    }

    /**
     * A Swiss QR reference (27 digits) or an ISO 11649 creditor reference found in the movement.
     */
    private function structuredReference(string $text): ?string
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $text));

        if (preg_match('/(?<!\d)\d{27}(?!\d)/', $compact, $match) === 1) {
            return $match[0];
        }

        return preg_match('/RF\d{2}[A-Z0-9]{1,21}/', $compact, $match) === 1 ? $match[0] : null;
    }

    private function finish(OpenBankingSyncRun $run, string $status): OpenBankingSyncRun
    {
        $run->status = $status;
        $run->finished_at = now();
        $run->save();

        return $run;
    }
}
