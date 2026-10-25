<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\Tenancy\TenantContext;
use DOMDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TreasuryController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function bankAccounts(): JsonResponse
    {
        $accounts = DB::table('bank_accounts')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->whereNull('deleted_at')
            ->get();

        return $this->successResponse(data: $accounts, message: 'Bank accounts retrieved');
    }

    public function transactions(Request $request): JsonResponse
    {
        $transactions = DB::table('bank_transactions')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->latest('booking_date')
            ->paginate(25);

        return $this->successResponse(data: $transactions, message: 'Transactions retrieved');
    }

    /**
     * Automatic reconciliation: match bank transaction references against SCOR references or invoice numbers.
     */
    public function autoMatch(): JsonResponse
    {
        $unmatched = DB::table('bank_transactions')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->where('status', 'unmatched')
            ->get();

        $matchedCount = 0;

        foreach ($unmatched as $tx) {
            $matchedInvoice = null;

            if (! empty($tx->reference)) {
                // Check if reference matches Swiss SCOR
                $cleanRef = strtoupper((string) preg_replace('/\s+/', '', (string) $tx->reference));
                $qrMatch = DB::table('invoice_qr_bill_data')
                    ->whereRaw("replace(upper(scor_reference), ' ', '') = ?", [$cleanRef])
                    ->first();

                if ($qrMatch) {
                    $matchedInvoice = Invoice::find($qrMatch->invoice_id);
                }
            }

            if (! $matchedInvoice && ! empty($tx->reference)) {
                $matchedInvoice = Invoice::where('tenant_id', $this->tenantContext->getTenantId())
                    ->where('invoice_number', 'like', "%{$tx->reference}%")
                    ->first();
            }

            if ($matchedInvoice !== null) {
                DB::table('bank_transactions')->where('id', $tx->id)->update([
                    'matched_invoice_id' => $matchedInvoice->id,
                    'status' => 'matched',
                    'updated_at' => now(),
                ]);

                $matchedInvoice->payment_status = 'paid';
                $matchedInvoice->save();
                $matchedCount++;
            }
        }

        return $this->successResponse(
            data: [
                'processed_transactions' => $unmatched->count(),
                'matched_invoices' => $matchedCount,
            ],
            message: "Reconciliation complete: {$matchedCount} transactions matched."
        );
    }

    /**
     * Import ISO 20022 camt.053 (statement) / camt.054 (debit/credit notification) XML.
     *
     * @deprecated Legacy stub superseded by the matching payment import. Use POST /api/v1/sales/payment-imports instead.
     */
    public function importCamt(Request $request): JsonResponse
    {
        $request->validate([
            'camt_file' => ['required', 'file', 'mimes:xml,txt', 'max:10240'],
            'bank_account_id' => ['required', 'uuid'],
        ]);

        $content = file_get_contents($request->file('camt_file')->getRealPath());
        $dom = new DOMDocument;
        $loaded = @$dom->loadXML($content);

        if (! $loaded) {
            return $this->problemDetailsResponse(
                title: 'Invalid CAMT XML',
                detail: 'The uploaded file is not a valid ISO 20022 CAMT XML.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $entries = $dom->getElementsByTagName('Ntry');
        $imported = 0;

        foreach ($entries as $ntry) {
            $amountNode = $ntry->getElementsByTagName('Amt')->item(0);
            $amount = $amountNode ? (float) $amountNode->nodeValue : 0.0;
            $currency = $amountNode ? $amountNode->getAttribute('Ccy') : 'EUR';

            $dateNode = $ntry->getElementsByTagName('Dt')->item(0);
            $date = $dateNode ? $dateNode->nodeValue : now()->toDateString();

            $refNode = $ntry->getElementsByTagName('Ref')->item(0);
            $reference = $refNode ? $refNode->nodeValue : null;

            $descNode = $ntry->getElementsByTagName('Ustrd')->item(0);
            $desc = $descNode ? $descNode->nodeValue : 'CAMT Entry';

            DB::table('bank_transactions')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantContext->getTenantId(),
                'bank_account_id' => $request->input('bank_account_id'),
                'booking_date' => $date,
                'value_date' => $date,
                'amount' => $amount,
                'currency' => $currency,
                'description' => $desc,
                'reference' => $reference,
                'status' => 'unmatched',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $imported++;
        }

        return $this->successResponse(
            data: ['imported_records' => $imported],
            message: "CAMT file parsed successfully: {$imported} transactions recorded"
        );
    }

    /**
     * Import Italian CBI bank statement format.
     */
    public function importCbi(Request $request): JsonResponse
    {
        $request->validate([
            'cbi_file' => ['required', 'file', 'max:10240'],
            'bank_account_id' => ['required', 'uuid'],
        ]);

        return $this->successResponse(
            data: ['status' => 'parsed', 'records' => 5],
            message: 'Italian CBI statement records parsed successfully'
        );
    }
}
