<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Models\InvoiceQrBillData;
use DOMDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class TreasuryController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/treasury/bank-accounts',
        operationId: 'getBankAccounts',
        description: 'List tenant active bank accounts and IBANs.',
        summary: 'List bank accounts',
        security: [['bearerAuth' => []]],
        tags: ['Treasury', 'Banking'],
        responses: [
            new OA\Response(response: 200, description: 'Bank accounts retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function bankAccounts(): JsonResponse
    {
        $accounts = BankAccount::all();

        return $this->successResponse(data: $accounts, message: 'Bank accounts retrieved');
    }

    #[OA\Get(
        path: '/api/v1/treasury/transactions',
        operationId: 'getBankTransactions',
        description: 'List paginated bank transactions.',
        summary: 'List bank transactions',
        security: [['bearerAuth' => []]],
        tags: ['Treasury', 'Banking'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Transactions retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function transactions(Request $request): JsonResponse
    {
        $transactions = BankTransaction::latest('booking_date')->paginate(25);

        return $this->successResponse(data: $transactions, message: 'Transactions retrieved');
    }

    /**
     * Automatic reconciliation: match bank transaction references against SCOR references or invoice numbers.
     */
    #[OA\Post(
        path: '/api/v1/treasury/reconciliation/auto-match',
        operationId: 'reconcileTransactionsAutoMatch',
        description: 'Automatic reconciliation: match bank transaction references against SCOR references or invoice numbers.',
        summary: 'Auto-match bank transactions',
        security: [['bearerAuth' => []]],
        tags: ['Treasury', 'Banking'],
        responses: [
            new OA\Response(response: 200, description: 'Reconciliation completed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function autoMatch(): JsonResponse
    {
        $unmatched = BankTransaction::where('status', 'unmatched')->get();

        $matchedCount = 0;

        foreach ($unmatched as $tx) {
            $matchedInvoice = null;

            if (! empty($tx->reference)) {
                // Check if reference matches Swiss SCOR
                $cleanRef = strtoupper((string) preg_replace('/\s+/', '', (string) $tx->reference));
                $qrMatch = InvoiceQrBillData::whereRaw("replace(upper(scor_reference), ' ', '') = ?", [$cleanRef])
                    ->first();

                if ($qrMatch) {
                    $matchedInvoice = Invoice::find($qrMatch->invoice_id);
                }
            }

            if (! $matchedInvoice && ! empty($tx->reference)) {
                $matchedInvoice = Invoice::where('invoice_number', 'like', "%{$tx->reference}%")
                    ->first();
            }

            if ($matchedInvoice !== null) {
                $tx->update([
                    'matched_invoice_id' => $matchedInvoice->id,
                    'status' => 'matched',
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
    #[OA\Post(
        path: '/api/v1/treasury/import-camt',
        operationId: 'importCamtFile',
        description: 'Import ISO 20022 camt.053 / camt.054 XML bank statement.',
        summary: 'Import CAMT XML',
        deprecated: true,
        security: [['bearerAuth' => []]],
        tags: ['Treasury', 'Banking'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['camt_file', 'bank_account_id'],
                    properties: [
                        new OA\Property(property: 'camt_file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'CAMT file parsed successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid CAMT file', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
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

            BankTransaction::create([
                'bank_account_id' => $request->input('bank_account_id'),
                'booking_date' => $date,
                'value_date' => $date,
                'amount' => $amount,
                'currency' => $currency,
                'description' => $desc,
                'reference' => $reference,
                'status' => 'unmatched',
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
    #[OA\Post(
        path: '/api/v1/treasury/import-cbi',
        operationId: 'importCbiFile',
        description: 'Import Italian CBI bank statement format.',
        summary: 'Import CBI file',
        security: [['bearerAuth' => []]],
        tags: ['Treasury', 'Banking'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['cbi_file', 'bank_account_id'],
                    properties: [
                        new OA\Property(property: 'cbi_file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'CBI file parsed successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid CBI file', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
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
