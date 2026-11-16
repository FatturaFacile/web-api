<?php

declare(strict_types=1);

namespace App\Actions\Customs;

use App\DTOs\CustomsOriginEvaluation;
use App\Enums\CustomsOriginType;
use App\Models\Contact;
use App\Models\FreeTradeAgreement;
use App\Models\Invoice;
use App\Models\Product;

/**
 * Decides whether an invoice qualifies for preferential duty treatment and which proof of origin it requires.
 */
final class EvaluateCustomsOriginAction
{
    /**
     * @param  string|null  $authorizationOverride  customs authorization number explicitly supplied by the caller
     */
    public function execute(Invoice $invoice, ?string $authorizationOverride = null): CustomsOriginEvaluation
    {
        $invoice->loadMissing(['items', 'tenant']);

        $exporterCountry = strtoupper((string) $invoice->tenant?->country);
        // The invoice is already authorized by the caller, so the contact is resolved independently of the active tenant scope.
        $importerCountry = strtoupper((string) Contact::withoutGlobalScopes()->whereKey($invoice->contact_id)->value('country'));

        $preferentialItems = $invoice->items->filter(
            fn ($item): bool => $item->origin_type === CustomsOriginType::Preferential->value
        );
        $preferentialValue = round((float) $preferentialItems->sum('line_total'), 2);

        $agreement = $this->resolveAgreement($invoice, $exporterCountry, $importerCountry, $preferentialItems->pluck('product_id')->filter()->all());

        [$thresholdCurrency, $threshold, $comparableValue] = $this->resolveThreshold($invoice, $agreement, $preferentialValue);
        $aboveThreshold = $threshold !== null && $comparableValue > $threshold;

        [$authorizationNumber, $authorizationType] = $this->resolveAuthorization($invoice, $agreement, $authorizationOverride);

        $hasPreferentialLines = $preferentialItems->isNotEmpty();
        $declarationAllowed = $agreement !== null
            && $hasPreferentialLines
            && (! $aboveThreshold || $authorizationNumber !== null);

        return new CustomsOriginEvaluation(
            exporterCountry: $exporterCountry,
            importerCountry: $importerCountry,
            agreement: $agreement,
            preferentialValue: $preferentialValue,
            threshold: $threshold,
            thresholdCurrency: $thresholdCurrency,
            aboveThreshold: $aboveThreshold,
            authorizationNumber: $authorizationNumber,
            authorizationType: $authorizationType,
            declarationAllowed: $declarationAllowed,
            dutyExemptionEligible: $agreement !== null && $hasPreferentialLines,
        );
    }

    /**
     * @param  array<int, string>  $productIds
     */
    private function resolveAgreement(Invoice $invoice, string $exporterCountry, string $importerCountry, array $productIds): ?FreeTradeAgreement
    {
        if ($exporterCountry === '' || $importerCountry === '' || $exporterCountry === $importerCountry) {
            return null;
        }

        $candidates = FreeTradeAgreement::query()->active()->orderBy('code')->get()
            ->filter(fn (FreeTradeAgreement $a): bool => $a->coversCountries($exporterCountry, $importerCountry));

        if ($candidates->isEmpty()) {
            return null;
        }

        $preferredIds = array_filter([$invoice->free_trade_agreement_id]);
        if ($productIds !== []) {
            $preferredIds = [...$preferredIds, ...Product::query()->whereIn('id', $productIds)->pluck('preferential_agreement_id')->filter()->all()];
        }

        foreach ($preferredIds as $id) {
            $match = $candidates->firstWhere('id', $id);
            if ($match !== null) {
                return $match;
            }
        }

        return $candidates->first();
    }

    /**
     * The threshold is expressed in the document currency when it is EUR or CHF, otherwise in the exporter currency
     * (the value is then converted with the document exchange rate).
     *
     * @return array{0: string, 1: float|null, 2: float}
     */
    private function resolveThreshold(Invoice $invoice, ?FreeTradeAgreement $agreement, float $value): array
    {
        $currency = strtoupper((string) $invoice->currency);
        $comparable = $value;

        if (! in_array($currency, ['EUR', 'CHF'], true)) {
            $currency = strtoupper((string) ($invoice->tenant?->default_currency ?? 'EUR'));
            $comparable = round($value * (float) $invoice->exchange_rate, 2);
        }

        $threshold = match ($currency) {
            'CHF' => $agreement?->value_threshold_chf,
            default => $agreement?->value_threshold_eur,
        };

        return [$currency, $threshold !== null ? (float) $threshold : null, $comparable];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveAuthorization(Invoice $invoice, ?FreeTradeAgreement $agreement, ?string $override): array
    {
        $override = $override !== null ? trim($override) : null;
        if ($override !== null && $override !== '') {
            return [$override, $agreement?->supports_rex === true ? 'rex' : 'approved_exporter'];
        }

        if ($agreement === null) {
            return [null, null];
        }

        $rex = trim((string) $invoice->tenant?->rex_exporter_number);
        if ($agreement->supports_rex && $rex !== '') {
            return [$rex, 'rex'];
        }

        $approved = trim((string) $invoice->tenant?->customs_approved_exporter_number);
        if ($agreement->supports_approved_exporter && $approved !== '') {
            return [$approved, 'approved_exporter'];
        }

        return [null, null];
    }
}
