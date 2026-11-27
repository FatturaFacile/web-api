<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\PaymentFlows;

use App\Services\SwissQrBill\QrBillGeneratorService;
use Modules\Treasury\Services\IbanValidator;

/**
 * Business rules a payment file must satisfy before it is built, per flow (SIX Swiss Payment Standards, EPC SCT, CBI).
 * The XSD validation of the generated file comes afterwards; these rules catch what a schema cannot: IBAN checksums,
 * country and currency of the flow, the pairing QR-IBAN / QR reference, the permitted character set and the CUC.
 *
 * Amounts are compared as exact decimal strings.
 */
final readonly class PaymentFlowRules
{
    /**
     * Countries of the SEPA scheme (EPC list).
     *
     * @var list<string>
     */
    public const array SEPA_COUNTRIES = [
        'AD', 'AT', 'BE', 'BG', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GB', 'GR', 'HR', 'HU', 'IE', 'IS',
        'IT', 'LI', 'LT', 'LU', 'LV', 'MC', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'SM', 'VA',
    ];

    public const string MAX_AMOUNT = '999999999.99';

    /**
     * Characters of the Swiss Payment Standards (Latin character set) and of the SEPA basic set, a superset of both is
     * accepted; anything else (emoji, CJK, control characters) is refused instead of silently dropped.
     */
    private const string CHARSET = '/^[A-Za-z0-9 .,;:\'+\-\/()?*\[\]{}\\\\`´~!"#%&<>=@_$£\xC0-\xFF]*$/u';

    public function __construct(private IbanValidator $ibans, private QrBillGeneratorService $references) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{path: string, code: string, params: array<string, string|int>}>
     */
    public function check(PaymentFlow $flow, string $debtorIban, array $lines, ?string $cuc): array
    {
        $issues = [];
        $debtor = $this->ibans->check($debtorIban);

        if (! $debtor['is_valid']) {
            $issues[] = ['path' => 'bank_account_id', 'code' => 'debtor_iban_invalid', 'params' => []];
        } elseif ($flow === PaymentFlow::CbiSepaV03 && $debtor['country'] !== 'IT') {
            $issues[] = ['path' => 'bank_account_id', 'code' => 'debtor_iban_country', 'params' => ['country' => 'IT']];
        } elseif ($flow->isSwiss() && ! in_array($debtor['country'], ['CH', 'LI'], true)) {
            $issues[] = ['path' => 'bank_account_id', 'code' => 'debtor_iban_country', 'params' => ['country' => 'CH/LI']];
        }

        if ($flow->requiresCuc() && ($cuc === null || $cuc === '')) {
            $issues[] = ['path' => 'cuc', 'code' => 'cuc_required', 'params' => []];
        } elseif ($cuc !== null && $cuc !== '' && preg_match('/^[A-Z0-9]{8}$/', $cuc) !== 1) {
            $issues[] = ['path' => 'cuc', 'code' => 'cuc_invalid', 'params' => []];
        }

        if ($lines === []) {
            return [...$issues, ['path' => 'lines', 'code' => 'no_lines', 'params' => []]];
        }

        $currencies = [];

        foreach ($lines as $index => $line) {
            foreach ($this->checkLine($flow, $index, $line) as $issue) {
                $issues[] = $issue;
            }

            $currencies[strtoupper((string) ($line['currency'] ?? ''))] = true;
        }

        if (count($currencies) > 1) {
            $issues[] = ['path' => 'lines', 'code' => 'mixed_currency', 'params' => []];
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return list<array{path: string, code: string, params: array<string, string|int>}>
     */
    private function checkLine(PaymentFlow $flow, int $index, array $line): array
    {
        $issues = [];
        $path = "lines.{$index}";
        $currency = strtoupper((string) ($line['currency'] ?? ''));
        $amount = (string) ($line['amount'] ?? '0');
        $name = (string) ($line['recipient_name'] ?? '');
        $iban = $this->ibans->check((string) ($line['recipient_iban'] ?? ''));

        if (! in_array($currency, $flow->currencies(), true)) {
            $issues[] = ['path' => "{$path}.currency", 'code' => 'currency_not_allowed', 'params' => ['currency' => $currency]];
        }

        if (preg_match('/^\d+(\.\d{1,2})?$/', $amount) !== 1 || bccomp($amount, '0.01', 2) < 0 || bccomp($amount, self::MAX_AMOUNT, 2) > 0) {
            $issues[] = ['path' => "{$path}.amount", 'code' => 'amount_out_of_range', 'params' => []];
        }

        if (! $iban['is_valid']) {
            $issues[] = ['path' => "{$path}.recipient_iban", 'code' => 'iban_invalid', 'params' => []];
        } elseif ($flow->isSepa() && ! in_array($iban['country'], self::SEPA_COUNTRIES, true)) {
            $issues[] = ['path' => "{$path}.recipient_iban", 'code' => 'iban_country_not_allowed', 'params' => ['country' => (string) $iban['country']]];
        }

        $maxName = 70;

        if (mb_strlen($name) > $maxName) {
            $issues[] = ['path' => "{$path}.recipient_name", 'code' => 'name_too_long', 'params' => ['max' => $maxName]];
        }

        foreach (['recipient_name', 'remittance_reference'] as $field) {
            $text = (string) ($line[$field] ?? '');

            if ($text !== '' && preg_match(self::CHARSET, $text) !== 1) {
                $issues[] = ['path' => "{$path}.{$field}", 'code' => 'charset', 'params' => []];
            }
        }

        if (mb_strlen((string) ($line['remittance_reference'] ?? '')) > 140) {
            $issues[] = ['path' => "{$path}.remittance_reference", 'code' => 'remittance_too_long', 'params' => ['max' => 140]];
        }

        $reference = strtoupper((string) preg_replace('/\s+/', '', (string) ($line['structured_creditor_ref'] ?? '')));

        if ($reference !== '') {
            $valid = ctype_digit($reference)
                ? $this->references->validateScorReference($reference)
                : $this->references->validateIso11649($reference);

            if (! $valid) {
                $issues[] = ['path' => "{$path}.structured_creditor_ref", 'code' => 'reference_invalid', 'params' => []];
            }
        }

        return $issues;
    }
}
