<?php

declare(strict_types=1);

namespace App\Services\Sales\Cpq;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\QuoteSignatureRequest;
use App\Models\Translation;
use App\Rules\PngSignature;
use App\Services\Pdf\DocumentPdfService;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Sales\DocumentTotalsCalculator;
use App\Services\Sales\QuoteLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tokenized, time-limited customer review and e-signature of a quote.
 *
 * The link carries a 256-bit token of which only the SHA-256 is stored. Signing locks the quote as accepted and stores
 * tamper-evident audit metadata: time, IP address, user agent, the SHA-256 of the canonical quote payload as accepted
 * and the SHA-256 of the signed PDF. {@see self::verify()} recomputes the payload hash to prove the quote did not change
 * after the signature.
 */
final readonly class QuoteSignatureService
{
    public const int DefaultValidityHours = 168;

    public function __construct(
        private QuoteApprovalEvaluator $approvals,
        private QuoteLifecycleService $lifecycle,
        private DocumentTotalsCalculator $totals,
        private DocumentEventRecorder $events,
        private DocumentPdfService $pdf,
    ) {}

    /**
     * Creates a signature link for a quote that is cleared for the customer.
     *
     * @return array{token: string, request: QuoteSignatureRequest}
     *
     * @throws ValidationException
     */
    public function createLink(Quote $quote, string $locale, int $validityHours = self::DefaultValidityHours, ?string $userId = null): array
    {
        $this->assertCustomerReady($quote);

        $token = bin2hex(random_bytes(32));
        $request = $quote->signatureRequests()->create([
            'tenant_id' => $quote->tenant_id,
            'token_hash' => hash('sha256', $token),
            'locale' => in_array($locale, Translation::SUPPORTED_LOCALES, true) ? $locale : Translation::DEFAULT_LOCALE,
            'expires_at' => now()->addHours($validityHours),
            'status' => QuoteSignatureRequest::Pending,
            'created_by' => $userId,
        ]);

        $this->events->record($quote, 'signature_link_created', ['signature_request_id' => $request->id, 'expires_at' => $request->expires_at->toIso8601String()]);

        return ['token' => $token, 'request' => $request];
    }

    /**
     * The open signature request of a token.
     *
     * @throws ValidationException when the link is unknown, expired, revoked or already used
     */
    public function resolve(string $token): QuoteSignatureRequest
    {
        $request = QuoteSignatureRequest::query()->withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first();

        if ($request === null) {
            throw ValidationException::withMessages(['token' => __('sales.cpq.link_invalid')]);
        }

        if ($request->revoked_at !== null || $request->status === QuoteSignatureRequest::Revoked) {
            throw ValidationException::withMessages(['token' => __('sales.cpq.link_revoked')]);
        }

        if ($request->status !== QuoteSignatureRequest::Pending) {
            throw ValidationException::withMessages(['token' => __('sales.cpq.link_used')]);
        }

        if ($request->expires_at->isPast()) {
            throw ValidationException::withMessages(['token' => __('sales.cpq.link_expired')]);
        }

        return $request;
    }

    /**
     * Signs and accepts the quote.
     *
     * @param  array{signer_name: string, signer_role: string, purchase_order_number?: ?string, signature_type: string, signature_data: string, selected_optional_item_ids?: list<string>|null}  $data
     *
     * @throws ValidationException
     */
    public function sign(string $token, array $data, ?string $ipAddress, ?string $userAgent): QuoteSignatureRequest
    {
        $request = $this->resolve($token);

        $signed = DB::transaction(function () use ($request, $data, $ipAddress, $userAgent): QuoteSignatureRequest {
            $quote = Quote::query()->withoutGlobalScopes()->with('items')->whereKey($request->quote_id)->lockForUpdate()->firstOrFail();
            $locked = QuoteSignatureRequest::query()->withoutGlobalScopes()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== QuoteSignatureRequest::Pending) {
                throw ValidationException::withMessages(['token' => __('sales.cpq.link_used')]);
            }

            $this->assertCustomerReady($quote);
            $this->lifecycle->assertWithinValidity($quote);

            if ($data['signature_type'] === 'drawn') {
                $this->assertDrawnSignature($data['signature_data']);
            }

            $selected = $this->applyOptionalSelection($quote, $data['selected_optional_item_ids'] ?? null);
            $quote->refresh()->load('items');

            $payloadHash = $this->payloadHash($quote);
            $now = now();

            $quote->forceFill([
                'status' => QuoteStatus::Accepted->value,
                'signed_by' => $data['signer_name'],
                'signed_at' => $now,
                'signature_data' => $data['signature_type'] === 'drawn' ? $data['signature_data'] : null,
            ])->save();

            $locked->forceFill([
                'status' => QuoteSignatureRequest::Signed,
                'signer_name' => $data['signer_name'],
                'signer_role' => $data['signer_role'],
                'purchase_order_number' => $data['purchase_order_number'] ?? null,
                'signature_type' => $data['signature_type'],
                'signature_data' => $data['signature_data'],
                'selected_optional_item_ids' => $selected,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'payload_hash' => $payloadHash,
                'signed_at' => $now,
            ])->save();

            $this->events->record($quote, 'signed_online', ['signature_request_id' => $locked->id, 'signed_by' => $data['signer_name'], 'payload_hash' => $payloadHash, 'ip_address' => $ipAddress]);

            return $locked;
        });

        // The PDF is generated after the commit: it embeds the audit certificate of the signed request.
        $quote = $signed->quote()->withoutGlobalScopes()->firstOrFail();
        $content = $this->pdf->render($quote->fresh(['items', 'contact', 'tenant']) ?? $quote, ['locale' => $signed->locale])->content;
        $signed->forceFill(['pdf_hash' => hash('sha256', $content)])->save();

        return $signed;
    }

    /**
     * The customer asks changes instead of signing: the link is closed and the request recorded on the quote.
     *
     * @throws ValidationException
     */
    public function requestChanges(string $token, string $message, ?string $ipAddress, ?string $userAgent): QuoteSignatureRequest
    {
        $request = $this->resolve($token);

        return DB::transaction(function () use ($request, $message, $ipAddress, $userAgent): QuoteSignatureRequest {
            $request->forceFill([
                'status' => QuoteSignatureRequest::ChangesRequested,
                'change_request' => $message,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ])->save();
            $quote = Quote::query()->withoutGlobalScopes()->whereKey($request->quote_id)->firstOrFail();
            $this->events->record($quote, 'changes_requested', ['signature_request_id' => $request->id, 'message' => $message]);

            return $request;
        });
    }

    public function revoke(QuoteSignatureRequest $request): QuoteSignatureRequest
    {
        if ($request->status === QuoteSignatureRequest::Pending) {
            $request->forceFill(['status' => QuoteSignatureRequest::Revoked, 'revoked_at' => now()])->save();
        }

        return $request;
    }

    /**
     * Whether the quote is still exactly as the customer signed it.
     *
     * @return array{valid: bool, expected_hash: string|null, actual_hash: string}
     */
    public function verify(QuoteSignatureRequest $request): array
    {
        $quote = Quote::query()->withoutGlobalScopes()->with('items')->whereKey($request->quote_id)->firstOrFail();
        $actual = $this->payloadHash($quote);

        return [
            'valid' => $request->status === QuoteSignatureRequest::Signed && $request->payload_hash !== null && hash_equals($request->payload_hash, $actual),
            'expected_hash' => $request->payload_hash,
            'actual_hash' => $actual,
        ];
    }

    /**
     * SHA-256 of the canonical payload of a quote: header figures and lines in a fixed order, so the same quote always
     * gives the same hash.
     */
    public function payloadHash(Quote $quote): string
    {
        $quote->loadMissing('items');

        $payload = [
            'quote_number' => $quote->quote_number,
            'contact_id' => $quote->contact_id,
            'currency' => $quote->currency,
            'date' => $quote->date->toDateString(),
            'valid_until' => $quote->valid_until?->toDateString(),
            'total_net' => (string) $quote->total_net,
            'total_tax' => (string) $quote->total_tax,
            'total_gross' => (string) $quote->total_gross,
            'items' => $quote->items->sortBy('id')->map(static fn (QuoteItem $item): array => [
                'product_id' => $item->product_id,
                'description' => $item->description,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'tax_rate' => (string) $item->tax_rate,
                'discount_percentage' => (string) $item->discount_percentage,
                'line_total' => (string) $item->line_total,
                'is_optional' => (bool) $item->is_optional,
                'is_selected' => (bool) $item->is_selected,
            ])->values()->all(),
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @throws ValidationException
     */
    private function assertCustomerReady(Quote $quote): void
    {
        if (! in_array($quote->status, [QuoteStatus::Draft->value, QuoteStatus::Approved->value, QuoteStatus::Sent->value], true)) {
            throw ValidationException::withMessages(['quote' => __('sales.cpq.quote_not_signable')]);
        }

        if (! $this->approvals->isCleared($quote)) {
            throw ValidationException::withMessages(['quote' => __('sales.cpq.approval_required')]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertDrawnSignature(string $signature): void
    {
        $binary = PngSignature::decode($signature);

        if ($binary === null || ! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            throw ValidationException::withMessages(['signature_data' => __('sales.cpq.signature_invalid')]);
        }
    }

    /**
     * Sets the optional lines the customer takes and recomputes the totals; null leaves the selection as it is.
     *
     * @param  list<string>|null  $selectedIds
     * @return list<string>
     */
    private function applyOptionalSelection(Quote $quote, ?array $selectedIds): array
    {
        $optional = $quote->items->where('is_optional', true);

        if ($selectedIds === null || ! $quote->allow_optional_selection) {
            return $optional->where('is_selected', true)->pluck('id')->values()->all();
        }

        $unknown = array_diff($selectedIds, $optional->pluck('id')->all());

        if ($unknown !== []) {
            throw ValidationException::withMessages(['selected_optional_item_ids' => __('sales.cpq.optional_item_unknown')]);
        }

        foreach ($optional as $item) {
            $item->forceFill(['is_selected' => in_array($item->id, $selectedIds, true)])->save();
        }

        $billable = $quote->items()->get()->filter(static fn (QuoteItem $item): bool => ! $item->is_optional || $item->is_selected)
            ->map(static fn (QuoteItem $item): array => [
                'description' => $item->description,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'tax_rate' => (string) $item->tax_rate,
                'discount_percentage' => (string) $item->discount_percentage,
            ])->values()->all();
        $computed = $this->totals->calculateDecimal($billable);
        $quote->forceFill(['total_net' => $computed['total_net'], 'total_tax' => $computed['total_tax'], 'total_gross' => $computed['total_gross'], 'subtotal' => $computed['subtotal'], 'discount_amount' => $computed['discount_total']])->save();

        return array_values(array_intersect($selectedIds, $optional->pluck('id')->all()));
    }
}
