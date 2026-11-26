<?php

declare(strict_types=1);

namespace Modules\DataMigration\Services;

use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Product;
use App\Services\Crm\Fiscal\FiscalIdentifiers;
use App\Services\SwissQrBill\QrBillGeneratorService;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\DataMigration\Domain\Enums\MigrationJobStatus;
use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;
use Modules\DataMigration\Models\MigrationJob;
use Modules\DataMigration\Models\MigrationStagingRow;
use Modules\DataMigration\Models\MigrationValidationError;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Runs mandatory dry-run simulation inside a database transaction / savepoint without committing business data.
 * Validates IT/CH fiscal identifiers (P.IVA, Codice Fiscale, Swiss UID), IBAN/QR-IBAN, referential integrity,
 * and double-entry debit/credit balance (dare == avere) for OpeningGlBalances.
 */
final class MigrationDryRunValidator
{
    public function __construct(private readonly QrBillGeneratorService $qrBill) {}

    /**
     * @return array<string, mixed>
     */
    public function validate(MigrationJob $job): array
    {
        return DB::transaction(function () use ($job): array {
            MigrationValidationError::query()->where('migration_job_id', $job->id)->forceDelete();

            $metadata = (array) ($job->source_metadata_json ?? []);
            $mappingsByEntity = (array) ($metadata['mappings_by_entity'] ?? []);
            $transformations = (array) ($metadata['value_transformations'] ?? []);

            $stagingRows = MigrationStagingRow::query()
                ->where('migration_job_id', $job->id)
                ->orderBy('row_index')
                ->get();

            $existingAccountCodes = ChartOfAccount::query()
                ->pluck('code')
                ->map(static fn (mixed $c): string => (string) $c)
                ->flip()
                ->all();

            $stagedAccountCodes = [];
            foreach ($stagingRows as $row) {
                if ($row->entity_type === MigrationTargetEntity::ChartOfAccounts->value) {
                    $mappedPreview = $this->applyMapping(
                        (array) $row->raw_payload_json,
                        (array) ($mappingsByEntity[$row->entity_type] ?? []),
                        $transformations,
                    );
                    if (! empty($mappedPreview['code'])) {
                        $stagedAccountCodes[(string) $mappedPreview['code']] = true;
                    }
                }
            }

            $knownCurrencies = Currency::query()
                ->pluck('code')
                ->map(static fn (mixed $c): string => strtoupper((string) $c))
                ->flip()
                ->all();
            if ($knownCurrencies === []) {
                $knownCurrencies = ['EUR' => true, 'CHF' => true, 'USD' => true, 'GBP' => true];
            }

            $existingVatSet = Contact::query()
                ->whereNotNull('vat_number')
                ->pluck('vat_number')
                ->map(static fn (mixed $v): string => FiscalIdentifiers::normalizeItalianVat((string) $v))
                ->flip()
                ->all();

            $existingSkuSet = Product::query()
                ->pluck('sku')
                ->map(static fn (mixed $s): string => mb_strtolower((string) $s))
                ->flip()
                ->all();

            $seenKeys = [];
            $errorsToInsert = [];
            $entityCounts = [];
            $fiscalChecksFailed = 0;
            $referentialChecksFailed = 0;
            $validRowsCount = 0;
            $errorRowsSet = [];

            $totalDebit = '0';
            $totalCredit = '0';
            $hasGlOpeningRows = false;

            foreach ($stagingRows as $row) {
                $entityType = $row->entity_type;
                $entityCounts[$entityType] = ($entityCounts[$entityType] ?? 0) + 1;
                $entityMappings = (array) ($mappingsByEntity[$entityType] ?? []);

                $mapped = $this->applyMapping((array) $row->raw_payload_json, $entityMappings, $transformations);
                $rowErrors = [];

                // 1. Required fields check
                $entityEnum = MigrationTargetEntity::tryFrom($entityType);
                if ($entityEnum !== null) {
                    foreach ($entityEnum->schemaFields() as $fieldDef) {
                        $fName = $fieldDef['field'];
                        if ($fieldDef['required'] && ($mapped[$fName] ?? '') === '') {
                            $rowErrors[] = $this->makeError(
                                $job,
                                $row,
                                $fName,
                                '',
                                'MISSING_REQUIRED_FIELD',
                                $fieldDef['sample'] !== '' ? $fieldDef['sample'] : null,
                            );
                        }
                    }
                }

                // 2. Fiscal & Banking validators (IT & CH)
                $country = strtoupper((string) ($mapped['country'] ?? $mapped['counterparty_country'] ?? ($transformations['currency_default'] === 'CHF' ? 'CH' : 'IT')));

                $vatValue = (string) ($mapped['vat_number'] ?? $mapped['counterparty_vat'] ?? '');
                if ($vatValue !== '' && ($country === 'IT' || preg_match('/^(IT)?\d{11}$/i', str_replace([' ', '.', '-'], '', $vatValue)) === 1)) {
                    if (! FiscalIdentifiers::isValidItalianVat($vatValue)) {
                        $fiscalChecksFailed++;
                        $rowErrors[] = $this->makeError(
                            $job,
                            $row,
                            isset($mapped['vat_number']) ? 'vat_number' : 'counterparty_vat',
                            $vatValue,
                            'INVALID_ITALIAN_VAT',
                            $this->suggestItalianVatFix($vatValue),
                        );
                    } else {
                        $normalizedVat = FiscalIdentifiers::normalizeItalianVat($vatValue);
                        if (isset($mapped['vat_number'])) {
                            $mapped['vat_number'] = $normalizedVat;
                        }
                    }
                }

                $cfValue = (string) ($mapped['fiscal_code'] ?? '');
                if ($cfValue !== '' && ! FiscalIdentifiers::isValidItalianFiscalCode($cfValue)) {
                    $fiscalChecksFailed++;
                    $rowErrors[] = $this->makeError(
                        $job,
                        $row,
                        'fiscal_code',
                        $cfValue,
                        'INVALID_ITALIAN_FISCAL_CODE',
                        FiscalIdentifiers::normalizeFiscalCode($cfValue),
                    );
                }

                $uidValue = (string) ($mapped['uid_number'] ?? '');
                if ($uidValue !== '' && ! FiscalIdentifiers::isValidSwissUid($uidValue)) {
                    $fiscalChecksFailed++;
                    $rowErrors[] = $this->makeError(
                        $job,
                        $row,
                        'uid_number',
                        $uidValue,
                        'INVALID_SWISS_UID',
                        $this->suggestSwissUidFix($uidValue),
                    );
                } elseif ($uidValue !== '') {
                    $mapped['uid_number'] = FiscalIdentifiers::normalizeSwissUid($uidValue) ?? $uidValue;
                }

                $ibanValue = (string) ($mapped['iban'] ?? '');
                if ($ibanValue !== '' && ! $this->qrBill->validateIbanChecksum($ibanValue)) {
                    $fiscalChecksFailed++;
                    $rowErrors[] = $this->makeError(
                        $job,
                        $row,
                        'iban',
                        $ibanValue,
                        'INVALID_IBAN_CHECKSUM',
                        $this->suggestIbanFix($ibanValue),
                    );
                }

                $qrIbanValue = (string) ($mapped['qr_iban'] ?? '');
                if ($qrIbanValue !== '' && ! $this->qrBill->isValidQrIban($qrIbanValue)) {
                    $fiscalChecksFailed++;
                    $rowErrors[] = $this->makeError(
                        $job,
                        $row,
                        'qr_iban',
                        $qrIbanValue,
                        'INVALID_SWISS_QR_IBAN',
                        $this->suggestIbanFix($qrIbanValue),
                    );
                }

                // 3. Currency referential check
                $currency = strtoupper((string) ($mapped['currency'] ?? ''));
                if ($currency !== '' && ! isset($knownCurrencies[$currency])) {
                    $referentialChecksFailed++;
                    $rowErrors[] = $this->makeError(
                        $job,
                        $row,
                        'currency',
                        $currency,
                        'UNKNOWN_CURRENCY_CODE',
                        (string) ($transformations['currency_default'] ?? 'EUR'),
                    );
                }

                // 4. Entity-specific checks (GL balance, AR/AP, Invoices, Duplicates)
                if ($entityType === MigrationTargetEntity::OpeningGlBalances->value) {
                    $hasGlOpeningRows = true;
                    $accCode = (string) ($mapped['account_code'] ?? '');
                    if ($accCode !== '' && ! isset($existingAccountCodes[$accCode]) && ! isset($stagedAccountCodes[$accCode])) {
                        $referentialChecksFailed++;
                        $rowErrors[] = $this->makeError(
                            $job,
                            $row,
                            'account_code',
                            $accCode,
                            'UNKNOWN_ACCOUNT_CODE',
                            array_key_first($existingAccountCodes) !== null ? (string) array_key_first($existingAccountCodes) : null,
                        );
                    }

                    $debit = $this->parseDecimal((string) ($mapped['debit_amount'] ?? '0'), $transformations);
                    $credit = $this->parseDecimal((string) ($mapped['credit_amount'] ?? '0'), $transformations);
                    $mapped['debit_amount'] = $debit;
                    $mapped['credit_amount'] = $credit;
                    $totalDebit = Decimal::add($totalDebit, $debit);
                    $totalCredit = Decimal::add($totalCredit, $credit);
                }

                if ($entityType === MigrationTargetEntity::OpenArApItems->value) {
                    $orig = $this->parseDecimal((string) ($mapped['original_amount'] ?? '0'), $transformations);
                    $open = $this->parseDecimal((string) ($mapped['open_amount'] ?? '0'), $transformations);
                    $mapped['original_amount'] = $orig;
                    $mapped['open_amount'] = $open;

                    if (Decimal::compare($open, $orig) > 0) {
                        $rowErrors[] = $this->makeError(
                            $job,
                            $row,
                            'open_amount',
                            $open,
                            'OPEN_AMOUNT_EXCEEDS_ORIGINAL',
                            $orig,
                        );
                    }
                }

                if ($entityType === MigrationTargetEntity::HistoricalInvoices->value) {
                    $net = $this->parseDecimal((string) ($mapped['total_net'] ?? '0'), $transformations);
                    $tax = $this->parseDecimal((string) ($mapped['total_tax'] ?? '0'), $transformations);
                    $gross = $this->parseDecimal((string) ($mapped['total_gross'] ?? '0'), $transformations);
                    $mapped['total_net'] = $net;
                    $mapped['total_tax'] = $tax;
                    $mapped['total_gross'] = $gross;

                    $expectedGross = Decimal::round2(Decimal::add($net, $tax));
                    $diff = abs((float) Decimal::sub($gross, $expectedGross));
                    if ($diff > 0.05) {
                        $rowErrors[] = $this->makeError(
                            $job,
                            $row,
                            'total_gross',
                            $gross,
                            'INVOICE_TOTAL_MISMATCH',
                            $expectedGross,
                        );
                    }
                }

                // Duplicate key check within batch or existing DB
                if ($entityType === MigrationTargetEntity::Products->value && ! empty($mapped['sku'])) {
                    $skuKey = mb_strtolower((string) $mapped['sku']);
                    if (isset($seenKeys['sku:'.$skuKey]) || isset($existingSkuSet[$skuKey])) {
                        $referentialChecksFailed++;
                        $rowErrors[] = $this->makeError(
                            $job,
                            $row,
                            'sku',
                            (string) $mapped['sku'],
                            'DUPLICATE_RECORD_KEY',
                            (string) $mapped['sku'].'-IMP',
                        );
                    }
                    $seenKeys['sku:'.$skuKey] = true;
                }

                if (in_array($entityType, [MigrationTargetEntity::Customers->value, MigrationTargetEntity::Vendors->value], true) && ! empty($mapped['vat_number'])) {
                    $vatKey = FiscalIdentifiers::normalizeItalianVat((string) $mapped['vat_number']);
                    if (isset($seenKeys[$entityType.':vat:'.$vatKey]) || isset($existingVatSet[$vatKey])) {
                        $referentialChecksFailed++;
                        $rowErrors[] = $this->makeError(
                            $job,
                            $row,
                            'vat_number',
                            (string) $mapped['vat_number'],
                            'DUPLICATE_RECORD_KEY',
                            null,
                        );
                    }
                    $seenKeys[$entityType.':vat:'.$vatKey] = true;
                }

                $isRowValid = $rowErrors === [];
                if ($isRowValid) {
                    $validRowsCount++;
                } else {
                    $errorRowsSet[$row->row_index] = true;
                    array_push($errorsToInsert, ...$rowErrors);
                }

                $row->update([
                    'mapped_payload_json' => $mapped,
                    'is_valid' => $isRowValid,
                ]);
            }

            // 5. Double-entry GL balance check (dare == avere)
            $isGlBalanced = true;
            $glDifference = '0.00';
            if ($hasGlOpeningRows) {
                $isGlBalanced = Decimal::compare($totalDebit, $totalCredit) === 0;
                $glDifference = Decimal::round2(Decimal::sub($totalDebit, $totalCredit));

                if (! $isGlBalanced) {
                    $firstGlRow = $stagingRows->firstWhere('entity_type', MigrationTargetEntity::OpeningGlBalances->value);
                    if ($firstGlRow !== null) {
                        $errorRowsSet[$firstGlRow->row_index] = true;
                        $errorsToInsert[] = $this->makeError(
                            $job,
                            $firstGlRow,
                            'credit_amount',
                            $totalCredit,
                            'UNBALANCED_OPENING_GL_BALANCES',
                            Decimal::round2($totalDebit),
                        );
                    }
                }
            }

            if ($errorsToInsert !== []) {
                foreach (array_chunk($errorsToInsert, 200) as $chunk) {
                    MigrationValidationError::query()->insert($chunk);
                }
            }

            $errorRowsCount = count($errorRowsSet);
            $passed = $stagingRows->isNotEmpty() && $errorRowsCount === 0 && $isGlBalanced;

            $summary = [
                'total_rows' => $stagingRows->count(),
                'valid_rows' => $validRowsCount,
                'error_rows' => $errorRowsCount,
                'error_count' => count($errorsToInsert),
                'entity_breakdown' => $entityCounts,
                'fiscal_checks_failed' => $fiscalChecksFailed,
                'referential_checks_failed' => $referentialChecksFailed,
                'gl_balance' => [
                    'has_opening_balances' => $hasGlOpeningRows,
                    'total_debit' => Decimal::round2($totalDebit),
                    'total_credit' => Decimal::round2($totalCredit),
                    'difference' => $glDifference,
                    'is_balanced' => $isGlBalanced,
                ],
                'dry_run_passed' => $passed,
            ];

            $job->update([
                'dry_run_passed' => $passed,
                'total_rows' => $stagingRows->count(),
                'error_rows' => $errorRowsCount,
                'dry_run_summary_json' => $summary,
                'dry_run_at' => now(),
                'status' => $passed ? MigrationJobStatus::DryRunValidated : MigrationJobStatus::Mapped,
            ]);

            return $summary;
        });
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  array<string, mixed>  $entityMappings
     * @param  array<string, mixed>  $transformations
     * @return array<string, mixed>
     */
    private function applyMapping(array $rawPayload, array $entityMappings, array $transformations): array
    {
        if ($entityMappings === []) {
            $mapped = $rawPayload;
        } else {
            $mapped = [];
            foreach ($entityMappings as $targetField => $config) {
                $sourceColumn = is_array($config) ? ($config['source_column'] ?? null) : (is_string($config) ? $config : null);
                $defaultValue = is_array($config) ? ($config['default_value'] ?? null) : null;

                $rawVal = null;
                if ($sourceColumn !== null && $sourceColumn !== '' && array_key_exists($sourceColumn, $rawPayload)) {
                    $rawVal = $rawPayload[$sourceColumn];
                } elseif (array_key_exists($targetField, $rawPayload)) {
                    $rawVal = $rawPayload[$targetField];
                }

                $val = trim((string) ($rawVal ?? ''));
                if ($val === '' && $defaultValue !== null) {
                    $val = (string) $defaultValue;
                }

                $mapped[$targetField] = $val;
            }
        }

        foreach (['date', 'due_date', 'posting_date', 'entry_date', 'hire_date'] as $dateField) {
            if (! empty($mapped[$dateField])) {
                $mapped[$dateField] = $this->normalizeDate((string) $mapped[$dateField], (string) ($transformations['date_format'] ?? 'Y-m-d'));
            }
        }

        if (empty($mapped['currency']) && ! empty($transformations['currency_default'])) {
            $mapped['currency'] = (string) $transformations['currency_default'];
        }

        return $mapped;
    }

    private function normalizeDate(string $value, string $preferredFormat): string
    {
        $clean = trim($value);
        foreach ([$preferredFormat, 'Y-m-d', 'd/m/Y', 'd.m.Y', 'Y/m/d'] as $fmt) {
            if ($fmt === '') {
                continue;
            }
            $dt = DateTimeImmutable::createFromFormat('!'.$fmt, $clean);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $transformations
     */
    private function parseDecimal(string $value, array $transformations): string
    {
        $clean = trim($value);
        if ($clean === '') {
            return '0.00';
        }

        $thousand = (string) ($transformations['thousand_separator'] ?? '');
        $decimal = (string) ($transformations['decimal_separator'] ?? '.');

        if ($thousand !== '') {
            $clean = str_replace($thousand, '', $clean);
        }
        $clean = str_replace(["'", ' '], '', $clean);

        if ($decimal === ',' || (str_contains($clean, ',') && ! str_contains($clean, '.'))) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        }

        return is_numeric($clean) ? number_format((float) $clean, 2, '.', '') : '0.00';
    }

    /**
     * @return array<string, mixed>
     */
    private function makeError(
        MigrationJob $job,
        MigrationStagingRow $row,
        string $fieldName,
        string $rawValue,
        string $errorCode,
        ?string $suggestedFix,
    ): array {
        $now = now();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $job->tenant_id,
            'migration_job_id' => $job->id,
            'row_index' => $row->row_index,
            'source_record_identifier' => $row->source_record_identifier,
            'entity_type' => $row->entity_type,
            'field_name' => $fieldName,
            'raw_value' => $rawValue,
            'error_code' => $errorCode,
            'message_key' => 'dataMigration.error.'.$errorCode,
            'suggested_fix' => $suggestedFix,
            'is_resolved' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function suggestItalianVatFix(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) !== 11) {
            return null;
        }

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $digit = (int) $digits[$i];
            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        $check = (10 - $sum % 10) % 10;

        return substr($digits, 0, 10).$check;
    }

    private function suggestSwissUidFix(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) !== 9) {
            return null;
        }

        $weights = [5, 4, 3, 2, 7, 6, 5, 4];
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += (int) $digits[$i] * $w;
        }

        $check = 11 - $sum % 11;
        if ($check === 11) {
            $check = 0;
        }
        if ($check === 10) {
            return null;
        }

        $fixed = substr($digits, 0, 8).$check;

        return sprintf('CHE-%s.%s.%s', substr($fixed, 0, 3), substr($fixed, 3, 3), substr($fixed, 6, 3));
    }

    private function suggestIbanFix(string $iban): ?string
    {
        $clean = strtoupper((string) preg_replace('/\s+/', '', $iban));
        if (strlen($clean) < 15) {
            return null;
        }

        $country = substr($clean, 0, 2);
        $bban = substr($clean, 4);
        $rearranged = $bban.$country.'00';
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_digit($char) ? $char : (string) (ord($char) - 55);
        }
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (($remainder.$chunk) % 97);
        }
        $check = str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT);

        return $country.$check.$bban;
    }
}
