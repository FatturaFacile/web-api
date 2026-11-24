<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Services;

use App\Models\Tenant;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\ItalianVat\Exceptions\ItalianVatException;
use Modules\ItalianVat\Models\CuCertification;

/**
 * Writes the telematic file of the Certificazione Unica: fixed-length records of 1900 characters ended by CR LF.
 *
 * Specification followed: "Specifiche tecniche per la trasmissione telematica delle Certificazioni Uniche" (Agenzia delle
 * Entrate, record length 1900, supply code CURyy). The supply is A (testa), B (frontespizio with the sender data), C
 * (sostituto d'imposta), then a D (percipiente) and an E (dati fiscali e previdenziali) record per certification, H
 * (riepilogo) and Z (coda, with the counts of every family and the control total). Positions, lengths and field kinds are
 * declared in {@see CuRecordLayout}. Amounts are written in euro cents, right-justified and zero-padded; text is upper-case
 * ASCII, left-justified and padded with spaces; dates are ddmmyyyy; position 1898 of every record holds the end marker "A".
 * The file must still be checked with the control software of the Agenzia delle Entrate for the year before it is
 * transmitted through Entratel or the Desktop Telematico.
 */
final class CuTelematicFileBuilder
{
    public const int RECORD_LENGTH = CuRecordLayout::RECORD_LENGTH;

    private const array AMOUNT_COLUMNS = [
        'gross' => 'gross_amount',
        'non_taxable' => 'non_taxable_amount',
        'taxable' => 'taxable_amount',
        'withheld' => 'withheld_amount',
        'contributions' => 'contributions_amount',
    ];

    public function __construct(private readonly CuCertificationService $certifications) {}

    /**
     * What stops the export: the company data and every certification must validate.
     *
     * @param  iterable<CuCertification>  $certifications
     * @return list<array{code: string, params: array<string, scalar>}>
     */
    public function preflight(Tenant $tenant, iterable $certifications): array
    {
        $errors = [];

        if (! CuFieldValidator::isValidTaxCode($this->companyTaxCode($tenant))) {
            $errors[] = ['code' => 'cu_company_tax_code_invalid', 'params' => []];
        }

        $contact = $this->contact($tenant);

        if (($contact['postal_code'] !== '' && ! CuFieldValidator::isValidPostalCode($contact['postal_code']))
            || ($contact['province'] !== '' && ! CuFieldValidator::isValidProvince($contact['province']))) {
            $errors[] = ['code' => 'cu_company_address_invalid', 'params' => []];
        }

        $count = 0;

        foreach ($certifications as $certification) {
            $count++;
            $recipient = ['recipient' => (string) $certification->recipient_name];

            foreach ($this->certifications->validate($certification->getAttributes()) as $error) {
                $errors[] = ['code' => $error['code'], 'params' => $error['params'] + $recipient];
            }

            $taxCode = $this->recipientTaxCode($certification);

            if ($taxCode !== '' && ! CuFieldValidator::isValidTaxCode($taxCode) && ! $this->hasError($errors, 'cu_tax_code_invalid', $recipient)) {
                $errors[] = ['code' => 'cu_tax_code_invalid', 'params' => ['tax_code' => $taxCode] + $recipient];
            }

            foreach (self::AMOUNT_COLUMNS as $name => $column) {
                $cents = $this->cents((string) $certification->{$column});

                if (! CuFieldValidator::isValidCents($cents, CuRecordLayout::E[$name][1])) {
                    $errors[] = ['code' => 'cu_field_too_long', 'params' => ['field' => $name, 'length' => CuRecordLayout::E[$name][1]] + $recipient];
                }
            }
        }

        if ($count === 0) {
            $errors[] = ['code' => 'cu_nothing_to_export', 'params' => []];
        }

        return $errors;
    }

    /**
     * @param  list<CuCertification>  $certifications
     * @return array{file_name: string, content: string}
     */
    public function build(Tenant $tenant, int $year, array $certifications, ?DateTimeInterface $createdAt = null): array
    {
        $errors = $this->preflight($tenant, $certifications);

        if ($errors !== []) {
            throw ItalianVatException::invalid('cu_export_invalid', ['count' => count($errors), 'first' => $errors[0]['code']]);
        }

        $taxCode = $this->companyTaxCode($tenant);
        $date = CuFieldValidator::formatDate($createdAt ?? Carbon::now());
        $count = count($certifications);
        $contact = $this->contact($tenant);
        $denomination = (string) ($tenant->legal_name ?: $tenant->name);
        $totals = array_fill_keys(array_keys(self::AMOUNT_COLUMNS), '0');

        $records = [
            $this->record('A', [
                'supplier_type' => CuRecordLayout::SUPPLIER_TYPE,
                'tax_code' => $taxCode,
                'year' => (string) $year,
                'certifications' => (string) $count,
                'creation_date' => $date,
            ], CuRecordLayout::SUPPLY_CODE.sprintf('%02d', $year % 100)),
            $this->record('B', [
                'substitute_tax_code' => $taxCode,
                'progressive' => '1',
                'sender_kind' => CuRecordLayout::SUPPLIER_TYPE,
                'sender_tax_code' => $taxCode,
                'denomination' => $denomination,
                'street' => $contact['street'],
                'postal_code' => $contact['postal_code'],
                'city' => $contact['city'],
                'province' => $contact['province'],
                'phone' => $contact['phone'],
                'email' => $contact['email'],
                'creation_date' => $date,
                'year' => (string) $year,
                'certifications' => (string) $count,
            ]),
            $this->record('C', [
                'substitute_tax_code' => $taxCode,
                'progressive' => '1',
                'vat_number' => $this->vatNumber($tenant, $taxCode),
                'denomination' => $denomination,
                'year' => (string) $year,
            ]),
        ];

        foreach ($certifications as $index => $certification) {
            $progressive = (string) ($index + 1);
            $recipientTaxCode = $this->recipientTaxCode($certification);
            $amounts = [];

            foreach (self::AMOUNT_COLUMNS as $name => $column) {
                $amounts[$name] = $this->cents((string) $certification->{$column});
                $totals[$name] = bcadd($totals[$name], $amounts[$name], 0);
            }

            $records[] = $this->record('D', [
                'substitute_tax_code' => $taxCode,
                'progressive' => $progressive,
                'recipient_tax_code' => $recipientTaxCode,
                'recipient_kind' => $certification->recipient_kind === 'natural' ? '1' : '2',
                'recipient_name' => (string) $certification->recipient_name,
                'reason' => (string) $certification->reason_code,
                'year' => (string) $year,
                'certificate_date' => CuFieldValidator::formatDate($certification->issued_at),
                'payments' => (string) (int) $certification->payments_count,
            ]);
            $records[] = $this->record('E', [
                'substitute_tax_code' => $taxCode,
                'progressive' => $progressive,
                'recipient_tax_code' => $recipientTaxCode,
                'reason' => (string) $certification->reason_code,
                'year' => (string) $year,
            ] + $amounts);
        }

        $records[] = $this->record('H', ['substitute_tax_code' => $taxCode, 'progressive' => '1', 'certifications' => (string) $count] + $totals);
        $records[] = $this->record('Z', [
            'a_records' => '1',
            'b_records' => '1',
            'c_records' => '1',
            'd_records' => (string) $count,
            'e_records' => (string) $count,
            'h_records' => '1',
            'total_records' => (string) (4 + 2 * $count + 1),
            'control_total' => $this->controlTotal($totals['gross']),
        ]);

        return ['file_name' => sprintf('IT%s_CU_%d.txt', $taxCode, $year), 'content' => implode('', $records)];
    }

    /**
     * Checks the structure of a produced file: record length and terminator, family order (A, B, C, D/E pairs, H, Z), the
     * progressive of every D/E pair, the counts of the A, B, H and Z records and the control total.
     *
     * @return list<string>
     */
    public function structuralErrors(string $content): array
    {
        $records = explode("\r\n", $content);

        if (array_pop($records) !== '') {
            return ['The file does not end with a record terminator.'];
        }

        $errors = [];

        foreach ($records as $index => $record) {
            if (($record[self::RECORD_LENGTH - 3] ?? '') !== CuRecordLayout::END_MARKER) {
                $errors[] = sprintf('Record %d lacks the end marker in position %d.', $index + 1, self::RECORD_LENGTH - 2);
            }

            if (strlen($record) !== self::RECORD_LENGTH - 2) {
                $errors[] = sprintf('Record %d is %d characters long, %d expected.', $index + 1, strlen($record) + 2, self::RECORD_LENGTH);
            }
        }

        $types = implode('', array_map(static fn (string $record): string => $record[0] ?? '?', $records));

        if (preg_match('/^ABC(DE)+HZ$/', $types) !== 1) {
            return [...$errors, 'The records must be ordered A, B, C, one D and E pair per certification, H and Z.'];
        }

        $pairs = intdiv(count($records) - 5, 2);
        $counts = ['A' => 1, 'B' => 1, 'C' => 1, 'D' => $pairs, 'E' => $pairs, 'H' => 1];
        $z = (string) end($records);

        foreach ($counts as $family => $expected) {
            if ($this->read($z, 'Z', strtolower($family).'_records') !== $expected) {
                $errors[] = sprintf('The %s record count of the Z record does not match the file.', $family);
            }
        }

        if ($this->read($z, 'Z', 'total_records') !== count($records)) {
            $errors[] = 'The total record count of the Z record does not match the file.';
        }

        if ($this->read($records[0], 'A', 'certifications') !== $pairs || $this->read($records[1], 'B', 'certifications') !== $pairs) {
            $errors[] = 'The certification count of the A or B record does not match the file.';
        }

        $gross = '0';

        for ($pair = 0; $pair < $pairs; $pair++) {
            $d = $records[3 + 2 * $pair];
            $e = $records[4 + 2 * $pair];

            if ($this->read($d, 'D', 'progressive') !== $pair + 1 || $this->read($e, 'E', 'progressive') !== $pair + 1) {
                $errors[] = sprintf('The D and E records of certification %d do not carry the progressive %d.', $pair + 1, $pair + 1);
            }

            $gross = bcadd($gross, (string) $this->read($e, 'E', 'gross'), 0);
        }

        $h = $records[count($records) - 2];

        if ((string) $this->read($h, 'H', 'gross') !== $gross) {
            $errors[] = 'The gross total of the H record does not match the E records.';
        }

        if ($this->controlTotal($gross) !== substr($z, CuRecordLayout::Z['control_total'][0] - 1, CuRecordLayout::Z['control_total'][1])) {
            $errors[] = 'The control total of the Z record does not match the file.';
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function record(string $family, array $values, ?string $supplyCode = null): string
    {
        $layout = CuRecordLayout::all()[$family];
        $buffer = str_repeat(' ', self::RECORD_LENGTH - 2);
        $buffer[0] = $family;
        $buffer[self::RECORD_LENGTH - 3] = CuRecordLayout::END_MARKER;

        if ($supplyCode !== null) {
            $buffer = substr_replace($buffer, str_pad($supplyCode, 5), CuRecordLayout::SUPPLY_CODE_POSITION - 1, 5);
        }

        foreach ($values as $field => $value) {
            [$start, $length, $kind] = $layout[$field];
            $text = match ($kind) {
                CuRecordLayout::NUMERIC => $this->numeric($value, $length, $field),
                CuRecordLayout::DATE => str_pad($value, $length),
                default => str_pad(substr(CuFieldValidator::sanitizeText($value), 0, $length), $length),
            };

            $buffer = substr_replace($buffer, $text, $start - 1, $length);
        }

        return $buffer."\r\n";
    }

    private function numeric(string $value, int $length, string $field): string
    {
        $digits = ltrim($value, '0');
        $digits = $digits === '' ? '0' : $digits;

        if (preg_match('/^\d+$/', $digits) !== 1 || strlen($digits) > $length) {
            throw ItalianVatException::invalid('cu_field_too_long', ['field' => $field, 'length' => $length]);
        }

        return str_pad($digits, $length, '0', STR_PAD_LEFT);
    }

    /** Reads a numeric field of a record as an integer. */
    private function read(string $record, string $family, string $field): int
    {
        [$start, $length] = CuRecordLayout::field($family, $field);

        return (int) substr($record, $start - 1, $length);
    }

    /** Control total of the Z record: the gross amount of the supply in cents, modulo the capacity of the field. */
    private function controlTotal(string $grossCents): string
    {
        $length = CuRecordLayout::Z['control_total'][1];

        return str_pad(substr(bcmod($grossCents, bcpow('10', (string) $length, 0)), -$length), $length, '0', STR_PAD_LEFT);
    }

    /**
     * @param  list<array{code: string, params: array<string, scalar>}>  $errors
     * @param  array<string, scalar>  $recipient
     */
    private function hasError(array $errors, string $code, array $recipient): bool
    {
        foreach ($errors as $error) {
            if ($error['code'] === $code && ($error['params']['recipient'] ?? null) === $recipient['recipient']) {
                return true;
            }
        }

        return false;
    }

    private function companyTaxCode(Tenant $tenant): string
    {
        $code = strtoupper(trim((string) ($tenant->fiscal_code ?: $tenant->vat_number)));

        return preg_replace('/^IT(?=\d{11}$)/', '', $code) ?? $code;
    }

    private function vatNumber(Tenant $tenant, string $taxCode): string
    {
        $vat = preg_replace('/^IT/i', '', trim((string) $tenant->vat_number)) ?? '';

        if (CuFieldValidator::isValidVatNumber($vat)) {
            return $vat;
        }

        return preg_match('/^\d{11}$/', $taxCode) === 1 ? $taxCode : '';
    }

    private function recipientTaxCode(CuCertification $certification): string
    {
        return strtoupper(trim((string) ($certification->recipient_tax_code ?: $certification->recipient_vat_number)));
    }

    /**
     * Sender contact data kept in the tenant settings (street, postal_code, city, province, phone, email).
     *
     * @return array{street: string, postal_code: string, city: string, province: string, phone: string, email: string}
     */
    private function contact(Tenant $tenant): array
    {
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $read = static fn (string $key): string => trim((string) ($settings[$key] ?? ''));

        return [
            'street' => $read('street'),
            'postal_code' => $read('postal_code'),
            'city' => $read('city'),
            'province' => strtoupper($read('province')),
            'phone' => $read('phone'),
            'email' => $read('email') !== '' ? $read('email') : trim((string) $tenant->pec_email),
        ];
    }

    private function cents(string $amount): string
    {
        return (string) (int) bcmul(Decimal::round2(Decimal::abs($amount)), '100', 0);
    }
}
