<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\LipeData;
use Modules\GeneralLedger\Services\Tax\LipeIdentifierValidator;
use Modules\GeneralLedger\Services\Tax\LipeXmlBuilder;
use Modules\GeneralLedger\Services\Tax\LipeXsdValidator;
use Modules\GeneralLedger\Services\Tax\VatAccountResolver;
use Modules\GeneralLedger\Services\VatFilingExportService;
use Modules\ItalianVat\Contracts\LipeSignatureVerifier;
use Modules\ItalianVat\Exceptions\ItalianVatException;
use Modules\ItalianVat\Models\LipeCommunication;

/**
 * The quarterly LIPE communication (D.L. 193/2016): gathers the periodic VAT settlements of the quarter (three monthly ones or
 * the quarterly one), builds the IVP18 file, checks it against the structural schema, reconciles the settlements with the
 * Erario c/IVA account of the ledger and follows the file through signature and transmission.
 *
 * Status flow: draft (file with schema errors) or validated, signed, ready, transmitted or rejected.
 */
final class LipeService
{
    public const string DRAFT = 'draft';

    public const string VALIDATED = 'validated';

    public const string SIGNED = 'signed';

    public const string READY = 'ready';

    public const string TRANSMITTED = 'transmitted';

    public const string REJECTED = 'rejected';

    /** Amounts that differ by less than this are the rounding of the ledger, not a reconciliation difference. */
    private const string TOLERANCE = '0.01';

    public function __construct(
        private readonly VatFilingExportService $exports,
        private readonly VatAccountResolver $accountResolver,
        private readonly LipeSignatureVerifier $signatures,
    ) {}

    /**
     * Builds, or rebuilds, the communication of a quarter. A communication already transmitted is never replaced; a rebuilt
     * one loses its signature, which was made on the previous file.
     */
    public function generate(string $tenantId, ?string $userId, int $year, int $quarter): LipeCommunication
    {
        if ($quarter < 1 || $quarter > 4) {
            throw ItalianVatException::invalid('lipe_quarter_invalid');
        }

        $existing = LipeCommunication::query()->where('tenant_id', $tenantId)->where('year', $year)->where('quarter', $quarter)->first();

        if ($existing !== null && in_array($existing->status, [self::TRANSMITTED], true)) {
            throw ItalianVatException::locked('lipe_already_transmitted', ['year' => $year, 'quarter' => $quarter]);
        }

        $settlements = $this->settlementsOf($tenantId, $year, $quarter);
        $modules = $settlements->map(fn (VatSettlement $settlement): LipeData => $this->exports->lipeData($settlement))->all();
        /** @var non-empty-list<LipeData> $modules */
        $xml = LipeXmlBuilder::buildModules($modules[0], $modules);
        $errors = [...LipeXsdValidator::errors($xml), ...LipeIdentifierValidator::errors($modules[0])];
        $reconciliation = $this->reconcile($tenantId, $settlements);

        $attributes = [
            'status' => $errors === [] ? self::VALIDATED : self::DRAFT,
            'settlement_ids' => $settlements->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            'totals' => $this->totals($settlements),
            'file_name' => LipeXmlBuilder::fileName($modules[0], $quarter),
            'xml_content' => $xml,
            'xsd_errors' => $errors,
            'reconciliation_status' => $reconciliation['status'],
            'reconciliation' => $reconciliation,
            'signature_status' => 'unsigned',
            'signature_digest' => null,
            'signer_fingerprint' => null,
            'signed_at' => null,
            'transmission_protocol' => null,
            'transmitted_at' => null,
            'rejection_reason' => null,
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing;
        }

        return LipeCommunication::create($attributes + ['tenant_id' => $tenantId, 'year' => $year, 'quarter' => $quarter, 'created_by' => $userId]);
    }

    /**
     * The final Italian settlements of a quarter, which must cover it completely: three monthly ones or one quarterly one.
     *
     * @return Collection<int, VatSettlement>
     */
    public function settlementsOf(string $tenantId, int $year, int $quarter): Collection
    {
        $from = CarbonImmutable::create($year, ($quarter - 1) * 3 + 1, 1)->startOfDay();
        $to = $from->addMonths(3)->subDay();

        $settlements = VatSettlement::query()
            ->where('tenant_id', $tenantId)
            ->where('country', 'IT')
            ->whereIn('status', [VatSettlement::STATUS_POSTED, VatSettlement::STATUS_PAID])
            ->whereDate('period_from', '>=', $from->toDateString())
            ->whereDate('period_from', '<=', $to->toDateString())
            ->orderBy('period_from')
            ->get();

        $quarterly = $settlements->first(fn (VatSettlement $settlement): bool => $settlement->period_to->format('Y-m') !== $settlement->period_from->format('Y-m'));

        if ($quarterly !== null && $settlements->count() === 1) {
            return $settlements;
        }

        $months = $settlements->map(fn (VatSettlement $settlement): int => (int) $settlement->period_from->format('n'))->unique()->values()->all();
        $missing = array_values(array_diff(range(($quarter - 1) * 3 + 1, $quarter * 3), $months));

        if ($settlements->isEmpty() || $missing !== [] || $quarterly !== null) {
            throw ItalianVatException::invalid('lipe_incomplete_periods', ['year' => $year, 'quarter' => $quarter, 'missing' => implode(',', $missing)]);
        }

        return $settlements;
    }

    /**
     * Compares each settlement with what the ledger holds on the Erario c/IVA account for its settlement entry: the amount to
     * pay (credit) or the credit position (debit).
     *
     * @param  Collection<int, VatSettlement>  $settlements
     * @return array{status: string, rows: list<array<string, mixed>>, total_expected: string, total_ledger: string, total_difference: string}
     */
    public function reconcile(string $tenantId, Collection $settlements): array
    {
        $accountId = $this->erarioAccountId($tenantId);
        $rows = [];
        $expectedTotal = '0';
        $ledgerTotal = '0';
        $balanced = true;

        foreach ($settlements as $settlement) {
            $expected = Decimal::round2((string) $settlement->payable_amount);
            $ledger = null;
            $status = 'missing_entry';

            if ($settlement->settlement_journal_id !== null && $accountId !== null) {
                $net = DB::table('journal_lines')
                    ->where('journal_entry_id', $settlement->settlement_journal_id)
                    ->where('account_id', $accountId)
                    ->whereNull('deleted_at')
                    ->selectRaw('COALESCE(SUM(credit_amount), 0) as credit, COALESCE(SUM(debit_amount), 0) as debit')
                    ->first();
                $ledger = Decimal::round2(Decimal::sub((string) ($net->credit ?? '0'), (string) ($net->debit ?? '0')));
                $difference = Decimal::sub($expected, $ledger);
                $status = Decimal::compare(Decimal::abs($difference), self::TOLERANCE) < 0 ? 'balanced' : 'difference';

                // A debt below the minimum is carried to the next period: the entry holds no payable amount.
                if ($status === 'difference' && $settlement->below_minimum_payment && Decimal::isZero($ledger)) {
                    $status = 'carried_forward';
                }
            }

            $balanced = $balanced && in_array($status, ['balanced', 'carried_forward'], true);
            $expectedTotal = Decimal::add($expectedTotal, $expected);
            $ledgerTotal = Decimal::add($ledgerTotal, $ledger ?? '0');

            $rows[] = [
                'settlement_id' => (string) $settlement->id,
                'period' => $settlement->period_from->toDateString().'/'.$settlement->period_to->toDateString(),
                'expected' => $expected,
                'ledger' => $ledger,
                'difference' => $ledger === null ? null : Decimal::round2(Decimal::sub($expected, $ledger)),
                'status' => $status,
            ];
        }

        return [
            'status' => $balanced && $rows !== [] ? 'balanced' : 'unbalanced',
            'rows' => $rows,
            'total_expected' => Decimal::round2($expectedTotal),
            'total_ledger' => Decimal::round2($ledgerTotal),
            'total_difference' => Decimal::round2(Decimal::sub($expectedTotal, $ledgerTotal)),
        ];
    }

    /**
     * Re-runs the reconciliation of a stored communication against the current ledger.
     */
    public function refreshReconciliation(string $tenantId, string $id): LipeCommunication
    {
        $communication = $this->find($tenantId, $id);
        $settlements = VatSettlement::query()->where('tenant_id', $tenantId)->whereIn('id', $communication->settlement_ids)->orderBy('period_from')->get();
        $reconciliation = $this->reconcile($tenantId, $settlements);
        $communication->update(['reconciliation' => $reconciliation, 'reconciliation_status' => $reconciliation['status']]);

        return $communication;
    }

    /**
     * Records the signature made outside the ERP with the certificate of the signer, after checking it against the file.
     */
    public function attachSignature(string $tenantId, string $id, string $signature, string $certificate): LipeCommunication
    {
        $communication = $this->find($tenantId, $id);

        if (! in_array($communication->status, [self::VALIDATED, self::SIGNED, self::READY], true)) {
            throw ItalianVatException::invalid('lipe_not_signable', ['status' => $communication->status]);
        }

        $xml = (string) $communication->xml_content;

        if (! $this->signatures->verify($xml, $signature, $certificate)) {
            $communication->update(['signature_status' => 'invalid', 'signature_digest' => null, 'signer_fingerprint' => null, 'signed_at' => null, 'status' => self::VALIDATED]);

            throw ItalianVatException::invalid('lipe_signature_invalid');
        }

        $communication->update([
            'status' => self::SIGNED,
            'signature_status' => 'signed',
            'signature_digest' => hash('sha256', $xml),
            'signer_fingerprint' => $this->signatures->fingerprint($certificate),
            'signed_at' => now(),
        ]);

        return $communication;
    }

    /**
     * Declares the file ready for Entratel / Desktop Telematico: it must be valid, signed on its current content and balanced
     * with the ledger.
     */
    public function markReady(string $tenantId, string $id): LipeCommunication
    {
        $communication = $this->refreshReconciliation($tenantId, $id);
        $problems = [];

        if (($communication->xsd_errors ?? []) !== []) {
            $problems[] = 'schema';
        }

        if ($communication->signature_status !== 'signed' || $communication->signature_digest !== hash('sha256', (string) $communication->xml_content)) {
            $problems[] = 'signature';
        }

        if ($communication->reconciliation_status !== 'balanced') {
            $problems[] = 'reconciliation';
        }

        if ($problems !== []) {
            throw ItalianVatException::invalid('lipe_not_ready', ['problems' => implode(',', $problems)]);
        }

        $communication->update(['status' => self::READY]);

        return $communication;
    }

    public function recordTransmission(string $tenantId, string $id, string $protocol, ?CarbonImmutable $at = null): LipeCommunication
    {
        $communication = $this->find($tenantId, $id);

        if ($communication->status !== self::READY) {
            throw ItalianVatException::invalid('lipe_not_ready', ['problems' => 'status']);
        }

        $communication->update(['status' => self::TRANSMITTED, 'transmission_protocol' => $protocol, 'transmitted_at' => $at ?? now(), 'rejection_reason' => null]);

        return $communication;
    }

    /**
     * The Agenzia rejected the file: the communication goes back to be corrected and generated again.
     */
    public function recordRejection(string $tenantId, string $id, string $reason): LipeCommunication
    {
        $communication = $this->find($tenantId, $id);

        if (! in_array($communication->status, [self::READY, self::TRANSMITTED], true)) {
            throw ItalianVatException::invalid('lipe_not_ready', ['problems' => 'status']);
        }

        $communication->update(['status' => self::REJECTED, 'rejection_reason' => $reason]);

        return $communication;
    }

    public function find(string $tenantId, string $id): LipeCommunication
    {
        return LipeCommunication::query()->where('tenant_id', $tenantId)->whereKey($id)->first()
            ?? throw ItalianVatException::notFound('lipe_not_found');
    }

    /**
     * @return Collection<int, LipeCommunication>
     */
    public function list(string $tenantId, ?int $year = null): Collection
    {
        return LipeCommunication::query()
            ->where('tenant_id', $tenantId)
            ->when($year !== null, fn ($query) => $query->where('year', $year))
            ->orderByDesc('year')
            ->orderByDesc('quarter')
            ->get();
    }

    /**
     * IVA a debito, IVA a credito, previous credit carried in, interest and the balance of the quarter.
     *
     * @param  Collection<int, VatSettlement>  $settlements
     * @return array<string, string>
     */
    private function totals(Collection $settlements): array
    {
        $sum = static fn (string $column): string => Decimal::round2($settlements->reduce(
            static fn (string $carry, VatSettlement $settlement): string => Decimal::add($carry, (string) $settlement->{$column}),
            '0',
        ));
        $output = $sum('output_vat_amount');
        $input = $sum('input_vat_amount');

        return [
            'active_operations' => $sum('taxable_turnover'),
            'passive_operations' => $sum('passive_operations'),
            'output_vat' => $output,
            'input_vat' => $input,
            'difference' => Decimal::round2(Decimal::sub($output, $input)),
            'previous_debit' => $sum('previous_debit'),
            'previous_credit' => Decimal::round2((string) ($settlements->first()->prior_credit_used ?? '0')),
            'interest' => $sum('interest_amount'),
            'balance' => $sum('payable_amount'),
        ];
    }

    private function erarioAccountId(string $tenantId): ?string
    {
        $configured = $this->accountResolver->settingsOf($tenantId)->settlement_account_id;

        if ($configured !== null) {
            return (string) $configured;
        }

        $account = ChartOfAccount::query()->where('tenant_id', $tenantId)->where('code', '2210')->first();

        return $account !== null ? (string) $account->id : null;
    }
}
