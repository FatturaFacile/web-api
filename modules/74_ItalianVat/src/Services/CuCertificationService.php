<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Services;

use App\Contracts\PdfGenerator;
use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\WithholdingSettlementLine;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\ItalianVat\Exceptions\ItalianVatException;
use Modules\ItalianVat\Models\CuCertification;

/**
 * Certificazione Unica for the self-employed professionals and the commercial agents paid in a year. The certifications are
 * built from the withholding register (the lines of the withholding settlements, by date of payment: the cash principle of
 * art. 4 DPR 322/1998): withheld income tax (RT01 and RT02) and the contributions withheld (INPS, ENASARCO, ENPAM, others).
 */
final class CuCertificationService
{
    /** Reason codes (causale) of the CU that this module issues. */
    public const array REASONS = ['A', 'B', 'C', 'D', 'E', 'G', 'H', 'I', 'L', 'L1', 'M', 'M1', 'M2', 'N', 'O', 'O1', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'V1', 'V2', 'W', 'X', 'Y', 'Z'];

    private const string DEFAULT_REASON = 'A';

    private const array TAX_TYPES = ['RT01', 'RT02'];

    public function __construct(private readonly PdfGenerator $pdfGenerator) {}

    /**
     * Rebuilds the draft certifications of a year from the withholding register. Issued certifications are kept as they were
     * issued; a substitute certification is a new issue, not an edit.
     *
     * @return Collection<int, CuCertification>
     */
    public function build(string $tenantId, int $year): Collection
    {
        $lines = WithholdingSettlementLine::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('paid_at', '>=', sprintf('%d-01-01', $year))
            ->whereDate('paid_at', '<=', sprintf('%d-12-31', $year))
            ->get();

        $groups = [];

        foreach ($lines as $line) {
            $reason = $line->reason !== null && $line->reason !== '' ? strtoupper($line->reason) : self::DEFAULT_REASON;
            $groups[$line->supplier_id.'|'.$reason][] = $line;
        }

        DB::transaction(function () use ($tenantId, $year, $groups): void {
            foreach ($groups as $key => $groupLines) {
                [$supplierId, $reason] = explode('|', $key);
                $existing = CuCertification::query()->where('tenant_id', $tenantId)->where('year', $year)->where('supplier_id', $supplierId)->where('reason_code', $reason)->first();

                if ($existing?->status === 'issued') {
                    continue;
                }

                $figures = $this->figures($groupLines);
                $recipient = $this->recipient($tenantId, $supplierId);
                $attributes = $figures + $recipient + ['reason_code' => $reason];
                $errors = $this->validate($attributes);
                $attributes['validation_errors'] = $errors;
                $attributes['status'] = $errors === [] ? 'validated' : 'draft';

                if ($existing !== null) {
                    $existing->update($attributes);

                    continue;
                }

                CuCertification::create($attributes + ['tenant_id' => $tenantId, 'year' => $year, 'supplier_id' => $supplierId]);
            }
        });

        return $this->list($tenantId, $year);
    }

    /**
     * @return Collection<int, CuCertification>
     */
    public function list(string $tenantId, int $year): Collection
    {
        return CuCertification::query()->where('tenant_id', $tenantId)->where('year', $year)->orderBy('recipient_name')->orderBy('reason_code')->get();
    }

    public function find(string $tenantId, string $id): CuCertification
    {
        return CuCertification::query()->where('tenant_id', $tenantId)->whereKey($id)->first()
            ?? throw ItalianVatException::notFound('cu_not_found');
    }

    /**
     * Marks the validated certifications of a year as issued. A year with certifications that do not validate cannot be
     * issued: the recipients would receive a certificate the telematic file rejects.
     *
     * @return Collection<int, CuCertification>
     */
    public function issue(string $tenantId, int $year): Collection
    {
        $certifications = $this->list($tenantId, $year);

        if ($certifications->isEmpty()) {
            throw ItalianVatException::invalid('cu_nothing_to_issue', ['year' => $year]);
        }

        $invalid = $certifications->filter(fn (CuCertification $certification): bool => $certification->status === 'draft');

        if ($invalid->isNotEmpty()) {
            throw ItalianVatException::invalid('cu_validation_failed', ['count' => $invalid->count()]);
        }

        foreach ($certifications as $certification) {
            if ($certification->status !== 'issued') {
                $certification->update(['status' => 'issued', 'issued_at' => now()]);
            }
        }

        return $this->list($tenantId, $year);
    }

    /**
     * The PDF of the certification of one recipient.
     *
     * @return array{file_name: string, content: string}
     */
    public function pdf(string $tenantId, string $id): array
    {
        $certification = $this->find($tenantId, $id);
        $tenant = Tenant::query()->findOrFail($tenantId);

        return [
            'file_name' => sprintf('cu-%d-%s-%s.pdf', $certification->year, preg_replace('/[^A-Za-z0-9]/', '', (string) ($certification->recipient_tax_code ?? $certification->recipient_vat_number)), $certification->reason_code),
            'content' => $this->pdfGenerator->render('italian-vat::cu-certification', ['tenant' => $tenant, 'certification' => $certification]),
        ];
    }

    /**
     * Checks a certification before it is issued or exported.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{code: string, params: array<string, scalar>}>
     */
    public function validate(array $data): array
    {
        $errors = [];
        $taxCode = strtoupper((string) ($data['recipient_tax_code'] ?? ''));

        if ($taxCode === '') {
            $errors[] = ['code' => 'cu_tax_code_missing', 'params' => []];
        } elseif (preg_match('/^[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]$/', $taxCode) !== 1 && preg_match('/^\d{11}$/', $taxCode) !== 1) {
            $errors[] = ['code' => 'cu_tax_code_invalid', 'params' => ['tax_code' => $taxCode]];
        }

        if (! in_array($data['reason_code'] ?? '', self::REASONS, true)) {
            $errors[] = ['code' => 'cu_reason_invalid', 'params' => ['reason' => (string) ($data['reason_code'] ?? '')]];
        }

        if (! Decimal::isPositive((string) ($data['gross_amount'] ?? '0'))) {
            $errors[] = ['code' => 'cu_gross_not_positive', 'params' => []];
        }

        if (Decimal::compare((string) ($data['withheld_amount'] ?? '0'), (string) ($data['taxable_amount'] ?? '0')) > 0) {
            $errors[] = ['code' => 'cu_withheld_exceeds_taxable', 'params' => []];
        }

        if (Decimal::compare((string) ($data['taxable_amount'] ?? '0'), (string) ($data['gross_amount'] ?? '0')) > 0) {
            $errors[] = ['code' => 'cu_taxable_exceeds_gross', 'params' => []];
        }

        if (trim((string) ($data['recipient_name'] ?? '')) === '') {
            $errors[] = ['code' => 'cu_name_missing', 'params' => []];
        }

        return $errors;
    }

    /**
     * Sums the payments of a recipient and reason. A payment can carry several lines (income tax and contributions): its gross
     * and taxable amounts are counted once.
     *
     * @param  list<WithholdingSettlementLine>  $lines
     * @return array<string, mixed>
     */
    private function figures(array $lines): array
    {
        $payments = [];
        $withheld = '0';
        $contributions = '0';

        foreach ($lines as $line) {
            $id = (string) $line->supplier_payment_id;
            $gross = $payments[$id]['gross'] ?? (string) $line->gross_paid;
            $taxable = $payments[$id]['taxable'] ?? '0';

            if (in_array($line->withholding_type, self::TAX_TYPES, true)) {
                $taxable = Decimal::add($taxable, (string) $line->taxable_amount);
                $withheld = Decimal::add($withheld, (string) $line->withheld_amount);
            } else {
                $contributions = Decimal::add($contributions, (string) $line->withheld_amount);
            }

            $payments[$id] = ['gross' => $gross, 'taxable' => $taxable];
        }

        $gross = '0';
        $taxable = '0';

        foreach ($payments as $payment) {
            $gross = Decimal::add($gross, $payment['gross']);
            $taxable = Decimal::add($taxable, $payment['taxable']);
        }

        return [
            'gross_amount' => Decimal::round2($gross),
            'taxable_amount' => Decimal::round2($taxable),
            'non_taxable_amount' => Decimal::round2(Decimal::isNegative(Decimal::sub($gross, $taxable)) ? '0' : Decimal::sub($gross, $taxable)),
            'withheld_amount' => Decimal::round2($withheld),
            'contributions_amount' => Decimal::round2($contributions),
            'payments_count' => count($payments),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function recipient(string $tenantId, string $supplierId): array
    {
        $contact = Contact::query()->where('tenant_id', $tenantId)->whereKey($supplierId)->first();
        $taxCode = strtoupper(trim((string) $contact?->fiscal_code));
        $vat = preg_replace('/^IT/i', '', trim((string) $contact?->vat_number));
        $name = $contact?->company_name ?: trim($contact?->last_name.' '.$contact?->first_name);

        return [
            'recipient_name' => (string) $name,
            'recipient_tax_code' => $taxCode !== '' ? $taxCode : null,
            'recipient_vat_number' => $vat !== '' ? $vat : null,
            'recipient_kind' => strlen($taxCode) === 16 ? 'natural' : 'legal',
        ];
    }
}
