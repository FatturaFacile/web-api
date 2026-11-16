<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Treasury\Exceptions\TreasuryOperationException;
use Modules\Treasury\Models\PagoPaPaymentNotice;

/**
 * pagoPA payment notices of receivables.
 *
 * Codes:
 * - IUV (unique payment identifier), 13 digits: an 11-digit progressive number taken from a per-tenant counter (zero
 *   padded) followed by 2 check digits, the progressive number modulo 93.
 * - Notice code (numero avviso), 18 digits: auxiliary digit "3", the 2-digit application code "02", the 13-digit IUV
 *   and 2 check digits, the 16 preceding digits modulo 93.
 * - QR payload: `PAGOPA|002|<noticeCode>|<creditorFiscalCode>|<amountInCents>`.
 *
 * The counter is advanced under a `TransactionLock` named after the tenant, so two concurrent notices never share an
 * IUV on any database; the unique index on (tenant, IUV) is the final guard.
 */
final readonly class PagoPaNoticeService
{
    public const string AuxiliaryDigit = '3';

    public const string ApplicationCode = '02';

    private const int Modulus = 93;

    /**
     * Issues a pending notice with a fresh IUV and the notice code derived from it.
     *
     * @param  array<string, mixed>  $data  Validated payload of StorePagoPaNoticeRequest
     */
    public function create(string $tenantId, array $data): PagoPaPaymentNotice
    {
        return DB::transaction(function () use ($tenantId, $data): PagoPaPaymentNotice {
            $iuv = self::buildIuv($this->nextSequence($tenantId));

            return PagoPaPaymentNotice::query()->create([
                'tenant_id' => $tenantId,
                'invoice_id' => $data['invoice_id'] ?? null,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'debtor_name' => $data['debtor_name'],
                'debtor_fiscal_code' => $data['debtor_fiscal_code'] ?? null,
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'EUR',
                'due_date' => $data['due_date'],
                'causale' => $data['causale'],
                'iuv' => $iuv,
                'notice_code' => self::buildNoticeCode($iuv),
                'creditor_fiscal_code' => $data['creditor_fiscal_code'],
                'status' => PagoPaPaymentNotice::Pending,
            ]);
        });
    }

    /**
     * Cancels a pending notice.
     *
     * @throws TreasuryOperationException
     */
    public function cancel(PagoPaPaymentNotice $notice): PagoPaPaymentNotice
    {
        $this->assertPending($notice, 'cancelled');
        $notice->forceFill(['status' => PagoPaPaymentNotice::Cancelled])->save();

        return $notice;
    }

    /**
     * Records the payment of a pending notice.
     *
     * @throws TreasuryOperationException
     */
    public function markPaid(PagoPaPaymentNotice $notice): PagoPaPaymentNotice
    {
        $this->assertPending($notice, 'marked as paid');
        $notice->forceFill(['status' => PagoPaPaymentNotice::Paid, 'paid_at' => now()])->save();

        return $notice;
    }

    /**
     * The string encoded in the QR code printed on the notice.
     */
    public function qrPayload(PagoPaPaymentNotice $notice): string
    {
        return implode('|', ['PAGOPA', '002', $notice->notice_code, $notice->creditor_fiscal_code, (string) self::amountInCents((string) $notice->amount)]);
    }

    /**
     * The 13-digit IUV of a progressive number: 11 digits plus 2 check digits.
     */
    public static function buildIuv(int $sequence): string
    {
        $reference = str_pad((string) $sequence, 11, '0', STR_PAD_LEFT);

        return $reference.self::checkDigits($reference);
    }

    /**
     * The 18-digit notice code of an IUV: auxiliary digit, application code, IUV and 2 check digits.
     */
    public static function buildNoticeCode(string $iuv): string
    {
        $body = self::AuxiliaryDigit.self::ApplicationCode.$iuv;

        return $body.self::checkDigits($body);
    }

    public static function amountInCents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * Two-digit remainder of a digit string modulo 93, computed digit by digit so it needs no big integers.
     */
    private static function checkDigits(string $digits): string
    {
        $remainder = 0;

        foreach (str_split($digits) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % self::Modulus;
        }

        return str_pad((string) $remainder, 2, '0', STR_PAD_LEFT);
    }

    private function nextSequence(string $tenantId): int
    {
        TransactionLock::acquire('pagopa-iuv:'.$tenantId);

        DB::table('pagopa_iuv_counters')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'last_sequence' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $next = (int) DB::table('pagopa_iuv_counters')->where('tenant_id', $tenantId)->value('last_sequence') + 1;
        DB::table('pagopa_iuv_counters')->where('tenant_id', $tenantId)->update(['last_sequence' => $next, 'updated_at' => now()]);

        return $next;
    }

    /**
     * @throws TreasuryOperationException
     */
    private function assertPending(PagoPaPaymentNotice $notice, string $action): void
    {
        if ($notice->status !== PagoPaPaymentNotice::Pending) {
            throw new TreasuryOperationException("Only a pending notice can be {$action}.", 'status');
        }
    }
}
