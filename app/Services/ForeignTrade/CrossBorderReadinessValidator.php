<?php

declare(strict_types=1);

namespace App\Services\ForeignTrade;

use App\Enums\Incoterm;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * Checks that a cross-border sales document carries what customs and Intrastat need before it is issued: the
 * Incoterm, and on every goods line the tariff code, the country of origin and the net weight.
 */
final class CrossBorderReadinessValidator
{
    public function __construct(private readonly CrossBorderVatRegimeResolver $regimes) {}

    /**
     * @param  array<string, mixed>  $header  tenant_id, contact_id, incoterm
     * @param  array<int, array<string, mixed>>  $lines  product_id, taric_code, country_of_origin
     * @return array{cross_border: bool, regime: array<string, mixed>|null, ready: bool, issues: list<array{code: string, line: int|null}>}
     */
    public function check(array $header, array $lines): array
    {
        $tenantCountry = (string) Tenant::query()->whereKey($header['tenant_id'] ?? null)->value('country');
        $contactCountry = (string) Contact::withoutGlobalScopes()->whereKey($header['contact_id'] ?? null)->value('country');
        $regime = $this->regimes->resolve($tenantCountry, $contactCountry);

        if ($regime['regime'] === CrossBorderVatRegimeResolver::DOMESTIC) {
            return ['cross_border' => false, 'regime' => null, 'ready' => true, 'issues' => []];
        }

        $issues = [];
        $incoterm = (string) ($header['incoterm'] ?? '');
        if ($incoterm === '') {
            $issues[] = ['code' => 'incoterm_missing', 'line' => null];
        } elseif (Incoterm::tryFrom($incoterm) === null) {
            $issues[] = ['code' => 'incoterm_invalid', 'line' => null];
        }

        $productIds = array_values(array_filter(array_column($lines, 'product_id')));
        /** @var array<string, Product> $products */
        $products = $productIds === [] ? [] : Product::withoutGlobalScopes()->where('tenant_id', $header['tenant_id'] ?? null)->whereIn('id', $productIds)->get()->keyBy('id')->all();

        foreach (array_values($lines) as $index => $line) {
            $product = $products[$line['product_id'] ?? ''] ?? null;
            if ($product === null && empty($line['taric_code'])) {
                continue; // a pure service / free-text line carries no goods data
            }

            $taric = (string) ($line['taric_code'] ?? $product->taric_code ?? $product->hs_code ?? '');
            $origin = (string) ($line['country_of_origin'] ?? $product->country_of_origin ?? '');

            if ($taric === '') {
                $issues[] = ['code' => 'hs_code_missing', 'line' => $index];
            }
            if ($origin === '') {
                $issues[] = ['code' => 'origin_country_missing', 'line' => $index];
            }
            if ($product === null || (float) $product->net_weight_kg <= 0.0) {
                $issues[] = ['code' => 'net_weight_missing', 'line' => $index];
            }
        }

        return ['cross_border' => true, 'regime' => $regime, 'ready' => $issues === [], 'issues' => $issues];
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $lines
     *
     * @throws ValidationException
     */
    public function assertReady(array $header, array $lines): void
    {
        $result = $this->check($header, $lines);
        if ($result['ready']) {
            return;
        }

        $messages = [];
        foreach ($result['issues'] as $issue) {
            $key = $issue['line'] === null ? 'incoterm' : 'items.'.$issue['line'].'.'.$issue['code'];
            $messages[$key][] = __('foreign_trade.'.$issue['code']);
        }

        throw ValidationException::withMessages($messages);
    }

    /**
     * @return array{cross_border: bool, regime: array<string, mixed>|null, ready: bool, issues: list<array{code: string, line: int|null}>}
     */
    public function checkInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing('items');

        return $this->check(
            ['tenant_id' => $invoice->tenant_id, 'contact_id' => $invoice->contact_id, 'incoterm' => $invoice->incoterm],
            $invoice->items->map(fn ($item): array => [
                'product_id' => $item->product_id,
                'taric_code' => $item->taric_code,
                'country_of_origin' => $item->country_of_origin,
            ])->all(),
        );
    }
}
