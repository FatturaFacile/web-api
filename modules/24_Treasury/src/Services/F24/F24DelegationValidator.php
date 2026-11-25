<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\F24;

use App\Services\Crm\Fiscal\FiscalIdentifiers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Treasury\Models\F24DelegationLine;
use Modules\Treasury\Models\F24TaxCode;

/**
 * Business rules of a Modello F24 delegation: taxpayer identity, section specific data of every line, one side (debit
 * or credit) per line and the balance of the credits compensated against the debits. All amounts are exact decimal
 * strings; nothing is rounded silently.
 *
 * The delegation may be a "zero balance" F24 (credits cancel the debits exactly): it carries no payment but is still
 * filed. Credits above the debits are refused, the surplus is not a payment.
 */
final class F24DelegationValidator
{
    /**
     * @param  array{taxpayer_fiscal_code: string, taxpayer_name: string, payment_date: string}  $header
     * @param  Collection<int, mixed>  $lines
     * @return list<array{path: string, code: string, params: array<string, string|int>}>
     */
    public function check(array $header, Collection $lines, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $issues = [];

        if (! FiscalIdentifiers::isValidItalianFiscalCode((string) $header['taxpayer_fiscal_code'])) {
            $issues[] = $this->issue('taxpayer_fiscal_code', 'taxpayer_fiscal_code_invalid');
        }

        if (trim((string) $header['taxpayer_name']) === '') {
            $issues[] = $this->issue('taxpayer_name', 'taxpayer_name_required');
        }

        if (CarbonImmutable::parse((string) $header['payment_date'])->startOfDay()->lessThan($today)) {
            $issues[] = $this->issue('payment_date', 'payment_date_past');
        }

        if ($lines->isEmpty()) {
            return [...$issues, $this->issue('lines', 'no_lines')];
        }

        $debit = '0.00';
        $credit = '0.00';
        $known = F24TaxCode::query()->where('is_active', true)->get(['section', 'code', 'requires_installment'])
            ->mapWithKeys(static fn (F24TaxCode $code): array => [$code->section.'|'.$code->code => (bool) $code->requires_installment]);

        foreach ($lines->values() as $index => $line) {
            $row = $line instanceof F24DelegationLine ? $line->getAttributes() : (array) $line;
            $path = "lines.{$index}";
            $section = (string) ($row['section'] ?? '');
            $code = strtoupper((string) ($row['tax_code'] ?? ''));
            $lineDebit = $this->amount($row['debit_amount'] ?? '0');
            $lineCredit = $this->amount($row['credit_amount'] ?? '0');

            if (! in_array($section, F24DelegationLine::SECTIONS, true)) {
                $issues[] = $this->issue("{$path}.section", 'section_invalid');

                continue;
            }

            if ($lineDebit === null || $lineCredit === null || bccomp($lineDebit, '0', 2) < 0 || bccomp($lineCredit, '0', 2) < 0) {
                $issues[] = $this->issue("{$path}.debit_amount", 'amount_invalid');

                continue;
            }

            if ((bccomp($lineDebit, '0', 2) > 0) === (bccomp($lineCredit, '0', 2) > 0)) {
                $issues[] = $this->issue("{$path}.debit_amount", 'one_side_only');

                continue;
            }

            if (! $known->has($section.'|'.$code) && ! in_array($section, [F24DelegationLine::SECTION_INPS, F24DelegationLine::SECTION_INAIL], true)) {
                $issues[] = $this->issue("{$path}.tax_code", 'tax_code_unknown', ['code' => $code]);
            }

            foreach ($this->sectionIssues($section, $row, $known->get($section.'|'.$code, false)) as $field => $issueCode) {
                $issues[] = $this->issue("{$path}.{$field}", $issueCode);
            }

            $debit = bcadd($debit, $lineDebit, 2);
            $credit = bcadd($credit, $lineCredit, 2);
        }

        if (bccomp($debit, '0', 2) === 0) {
            $issues[] = $this->issue('lines', 'no_debit');
        } elseif (bccomp($credit, $debit, 2) > 0) {
            $issues[] = $this->issue('lines', 'credit_exceeds_debit', ['debit' => $debit, 'credit' => $credit]);
        }

        return $issues;
    }

    /**
     * Debit, credit and balance of the lines, as exact decimal strings.
     *
     * @param  Collection<int, mixed>  $lines
     * @return array{debit: string, credit: string, payable: string}
     */
    public function totals(Collection $lines): array
    {
        $debit = '0.00';
        $credit = '0.00';

        foreach ($lines as $line) {
            $row = $line instanceof F24DelegationLine ? $line->getAttributes() : (array) $line;
            $debit = bcadd($debit, $this->amount($row['debit_amount'] ?? '0') ?? '0.00', 2);
            $credit = bcadd($credit, $this->amount($row['credit_amount'] ?? '0') ?? '0.00', 2);
        }

        return ['debit' => $debit, 'credit' => $credit, 'payable' => bcsub($debit, $credit, 2)];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string> field => issue code
     */
    private function sectionIssues(string $section, array $row, bool $requiresInstallment): array
    {
        $institution = (string) ($row['institution_code'] ?? '');
        $number = (string) ($row['reference_number'] ?? '');
        $period = (string) ($row['reference_period'] ?? '');
        $year = (string) ($row['reference_year'] ?? '');
        $installment = (string) ($row['installment'] ?? '');
        $issues = [];

        $needsYear = fn (): bool => preg_match('/^(19|20)\d{2}$/', $year) === 1;

        switch ($section) {
            case F24DelegationLine::SECTION_ERARIO:
                if (! $needsYear()) {
                    $issues['reference_year'] = 'reference_year_required';
                }

                if ($requiresInstallment && preg_match('/^\d{4}$/', $installment) !== 1) {
                    $issues['installment'] = 'installment_required';
                }

                break;
            case F24DelegationLine::SECTION_INPS:
                if (preg_match('/^\d{4}$/', $institution) !== 1) {
                    $issues['institution_code'] = 'institution_code_invalid';
                }

                if ($number === '') {
                    $issues['reference_number'] = 'reference_number_required';
                }

                if (preg_match('/^(0[1-9]|1[0-2])(19|20)\d{2}$/', $period) !== 1) {
                    $issues['reference_period'] = 'reference_period_invalid';
                }

                break;
            case F24DelegationLine::SECTION_REGIONI:
                if (preg_match('/^\d{2}$/', $institution) !== 1) {
                    $issues['institution_code'] = 'institution_code_invalid';
                }

                if (! $needsYear()) {
                    $issues['reference_year'] = 'reference_year_required';
                }

                break;
            case F24DelegationLine::SECTION_IMU:
                if (preg_match('/^[A-Z]\d{3}$/', strtoupper($institution)) !== 1) {
                    $issues['institution_code'] = 'cadastral_code_invalid';
                }

                if (! $needsYear()) {
                    $issues['reference_year'] = 'reference_year_required';
                }

                break;
            case F24DelegationLine::SECTION_INAIL:
                if (preg_match('/^\d{5}$/', $institution) !== 1) {
                    $issues['institution_code'] = 'institution_code_invalid';
                }

                if ($number === '') {
                    $issues['reference_number'] = 'reference_number_required';
                }

                break;
        }

        return $issues;
    }

    private function amount(mixed $value): ?string
    {
        $text = trim((string) $value);

        return preg_match('/^-?\d+(\.\d{1,2})?$/', $text) === 1 ? bcadd($text, '0', 2) : null;
    }

    /**
     * @param  array<string, string|int>  $params
     * @return array{path: string, code: string, params: array<string, string|int>}
     */
    private function issue(string $path, string $code, array $params = []): array
    {
        return ['path' => $path, 'code' => $code, 'params' => $params];
    }
}
