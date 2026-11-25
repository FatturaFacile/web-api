<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CorporateConventions\Domain\Enums\ReimbursementStatus;
use Modules\CorporateConventions\Domain\Enums\WelfareCategory;
use Modules\CorporateConventions\Domain\Enums\WelfareJurisdiction;
use Modules\CorporateConventions\Domain\Enums\WelfareProvider;
use Modules\CorporateConventions\Domain\Support\Dec;
use Modules\CorporateConventions\Domain\Support\IdentifierHasher;
use Modules\CorporateConventions\Domain\Support\WelfareVoucherPolicy;
use Modules\CorporateConventions\Exceptions\ConventionException;
use Modules\CorporateConventions\Models\WelfareVoucherRedemption;

/**
 * Welfare vouchers paid at the till: validation by jurisdiction, merchant fee, and the settlement with the provider.
 */
final class WelfareVoucherService
{
    public function __construct(private readonly WelfareVoucherPolicy $policy) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function redeem(array $data, string $tenantId, ?string $userId): WelfareVoucherRedemption
    {
        $jurisdiction = WelfareJurisdiction::from((string) $data['jurisdiction']);
        $provider = WelfareProvider::from((string) $data['provider']);
        $category = WelfareCategory::from((string) $data['welfare_category']);
        $currency = strtoupper((string) $data['currency']);
        $gross = Dec::round((string) $data['gross_voucher_amount']);

        $this->policy->assertAllowed($jurisdiction, $provider, $category, $currency, $gross);

        $code = (string) $data['voucher_code'];
        $hash = $this->barcodeHash($provider, $code);
        $percentage = isset($data['merchant_fee_percentage']) && $data['merchant_fee_percentage'] !== ''
            ? (string) $data['merchant_fee_percentage']
            : (string) config('corporate_conventions.welfare_fees.'.$provider->value, '0');

        if (Dec::cmp($percentage, '0') < 0 || Dec::cmp($percentage, '100') >= 0) {
            throw new ConventionException('welfare_fee_invalid', 422);
        }

        if (WelfareVoucherRedemption::query()->where('provider', $provider->value)->where('voucher_barcode_hash', $hash)->exists()) {
            throw new ConventionException('welfare_voucher_already_redeemed', 409);
        }

        $split = $this->policy->split($gross, $percentage);

        return WelfareVoucherRedemption::query()->create([
            'tenant_id' => $tenantId,
            'source_document_type' => $data['source_document_type'],
            'source_document_id' => $data['source_document_id'],
            'enrollment_id' => $data['enrollment_id'] ?? null,
            'jurisdiction' => $jurisdiction,
            'provider' => $provider,
            'welfare_category' => $category,
            'voucher_code_masked' => WelfareVoucherPolicy::mask($code),
            'voucher_barcode_hash' => $hash,
            'currency' => $currency,
            'gross_voucher_amount' => $gross,
            'merchant_fee_percentage' => Dec::round($percentage, 4),
            'merchant_fee_amount' => $split['fee'],
            'net_expected_reimbursement' => $split['net'],
            'reimbursement_status' => ReimbursementStatus::PendingSettlement,
            'created_by' => $userId,
        ]);
    }

    /**
     * Sends the pending vouchers of a provider to settlement under a batch reference.
     *
     * @param  list<string>  $ids
     */
    public function submitBatch(WelfareProvider $provider, array $ids, string $batchReference, ?string $userId): int
    {
        return DB::transaction(function () use ($provider, $ids, $batchReference, $userId): int {
            $rows = WelfareVoucherRedemption::query()->whereIn('id', $ids)->lockForUpdate()->get();

            if ($rows->count() !== count(array_unique($ids))) {
                throw new ConventionException('welfare_voucher_unknown', 404);
            }

            foreach ($rows as $row) {
                if ($row->provider !== $provider) {
                    throw new ConventionException('welfare_provider_mismatch', 422);
                }

                $row->submit($batchReference);
                $row->updated_by = $userId;
                $row->save();
            }

            return $rows->count();
        });
    }

    /**
     * Reconciles the vouchers of a provider against its settlement statement. A line is matched by the id of the redemption or by the
     * voucher code printed on the statement; the amount it settles must equal the expected net within the tolerance, else the voucher
     * is rejected to be disputed.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{reconciled: int, rejected: int, total_expected: string, total_settled: string, discrepancies: list<array<string, string>>}
     */
    public function reconcile(WelfareProvider $provider, string $batchReference, ?string $selfInvoiceNumber, array $lines, ?string $userId): array
    {
        return DB::transaction(function () use ($provider, $batchReference, $selfInvoiceNumber, $lines, $userId): array {
            $tolerance = (string) config('corporate_conventions.reconciliation_tolerance');
            $result = ['reconciled' => 0, 'rejected' => 0, 'total_expected' => '0', 'total_settled' => '0', 'discrepancies' => []];
            $seen = [];

            foreach ($lines as $line) {
                $row = $this->resolveLine($provider, $line);

                if (isset($seen[$row->id])) {
                    throw new ConventionException('welfare_statement_line_duplicated', 422);
                }

                $seen[$row->id] = true;
                $expected = (string) $row->net_expected_reimbursement;
                $settled = Dec::round((string) $line['settled_amount']);
                $matched = $row->settle($settled, $batchReference, $selfInvoiceNumber, $tolerance, now());
                $row->updated_by = $userId;
                $row->save();

                $result['total_expected'] = Dec::add($result['total_expected'], $expected);
                $result['total_settled'] = Dec::add($result['total_settled'], $settled);

                if ($matched) {
                    $result['reconciled']++;

                    continue;
                }

                $result['rejected']++;
                $result['discrepancies'][] = ['redemption_id' => $row->id, 'voucher_code_masked' => $row->voucher_code_masked, 'expected' => $expected, 'settled' => $settled, 'difference' => Dec::round(Dec::sub($settled, $expected))];
            }

            $result['total_expected'] = Dec::round($result['total_expected']);
            $result['total_settled'] = Dec::round($result['total_settled']);

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<WelfareVoucherRedemption>
     */
    public function query(array $filters): Builder
    {
        return WelfareVoucherRedemption::query()
            ->when($filters['provider'] ?? null, fn (Builder $query, string $value) => $query->where('provider', $value))
            ->when($filters['jurisdiction'] ?? null, fn (Builder $query, string $value) => $query->where('jurisdiction', $value))
            ->when($filters['status'] ?? null, fn (Builder $query, string $value) => $query->where('reimbursement_status', $value))
            ->when($filters['batch'] ?? null, fn (Builder $query, string $value) => $query->where('settlement_batch_reference', $value))
            ->when($filters['from'] ?? null, fn (Builder $query, string $value) => $query->whereDate('created_at', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $query, string $value) => $query->whereDate('created_at', '<=', $value))
            ->orderByDesc('created_at');
    }

    /**
     * Gross, merchant fee and net receivable by provider and status.
     *
     * @return list<array<string, mixed>>
     */
    public function summary(): array
    {
        return WelfareVoucherRedemption::query()
            ->selectRaw('provider, reimbursement_status, currency, COUNT(*) as vouchers, SUM(gross_voucher_amount) as gross, SUM(merchant_fee_amount) as fee, SUM(net_expected_reimbursement) as net')
            ->groupBy('provider', 'reimbursement_status', 'currency')
            ->orderBy('provider')
            ->get()
            ->map(static fn (WelfareVoucherRedemption $row): array => [
                'provider' => (string) $row->getRawOriginal('provider'),
                'reimbursement_status' => (string) $row->getRawOriginal('reimbursement_status'),
                'currency' => (string) $row->getAttribute('currency'),
                'vouchers' => (int) $row->getAttribute('vouchers'),
                'gross' => Dec::round((string) $row->getAttribute('gross')),
                'fee' => Dec::round((string) $row->getAttribute('fee')),
                'net' => Dec::round((string) $row->getAttribute('net')),
            ])->values()->all();
    }

    public function barcodeHash(WelfareProvider $provider, string $code): string
    {
        return hash('sha256', $provider->value.'|'.IdentifierHasher::normalize($code));
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function resolveLine(WelfareProvider $provider, array $line): WelfareVoucherRedemption
    {
        $query = WelfareVoucherRedemption::query()->lockForUpdate();
        $row = isset($line['redemption_id'])
            ? $query->whereKey($line['redemption_id'])->first()
            : $query->where('provider', $provider->value)->where('voucher_barcode_hash', $this->barcodeHash($provider, (string) ($line['voucher_code'] ?? '')))->first();

        if ($row === null) {
            throw new ConventionException('welfare_voucher_unknown', 404);
        }

        if ($row->provider !== $provider) {
            throw new ConventionException('welfare_provider_mismatch', 422);
        }

        return $row;
    }
}
