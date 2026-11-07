<?php

declare(strict_types=1);

namespace Modules\Customs\Services;

use App\Services\Sales\DocumentNumberingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Customs\Enums\DossierStatus;
use Modules\Customs\Enums\QuoteStatus;
use Modules\Customs\Enums\RateBasis;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Models\CustomsDossier;
use Modules\Customs\Models\CustomsQuote;
use Modules\Customs\Models\CustomsServiceDiscount;
use Modules\Customs\Models\CustomsTariffGrid;
use Modules\Customs\Models\CustomsTariffGridRate;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Prices a dossier: from the tariff grid of its service, with the discount of the customer, or as a spot quote.
 */
final class FreightPricingService
{
    public function __construct(private readonly DocumentNumberingService $numbering) {}

    public function quoteFromGrid(CustomsDossier $dossier, ?string $tariffGridId): CustomsQuote
    {
        $grid = $this->gridFor($dossier, $tariffGridId);
        $rate = $this->rateFor($grid, $dossier);

        $base = $this->priceOf($rate, $dossier);
        $discount = (string) (CustomsServiceDiscount::query()
            ->where('contact_id', $dossier->customer_contact_id)
            ->where('service_type', $dossier->service_type)
            ->value('discount_percent') ?? '0');

        $price = Decimal::round2(Decimal::sub($base, Decimal::div(Decimal::mul($base, $discount), '100')));

        return $this->store($dossier, [
            'is_spot' => false,
            'tariff_grid_id' => $grid->id,
            'discount_percent' => $discount,
            'currency' => $grid->currency,
        ], [['description' => "{$grid->name} ({$rate->basis->value})", 'quantity' => '1', 'unit_price' => $price]]);
    }

    /**
     * @param  list<array{description: string, quantity: string|int|float, unit_price: string|int|float}>  $lines
     */
    public function spotQuote(CustomsDossier $dossier, array $lines, ?string $validUntil): CustomsQuote
    {
        return $this->store($dossier, [
            'is_spot' => true,
            'currency' => $dossier->currency,
            'valid_until' => $validUntil,
        ], $lines);
    }

    public function setStatus(CustomsQuote $quote, QuoteStatus $status): CustomsQuote
    {
        if ($quote->status === QuoteStatus::Accepted || $quote->status === QuoteStatus::Rejected) {
            throw new CustomsException('The quote has already been answered.');
        }

        $quote->update(['status' => $status]);

        if ($status === QuoteStatus::Sent && $quote->dossier->status === DossierStatus::Open) {
            $quote->dossier->update(['status' => DossierStatus::Quoted]);
        }

        return $quote;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{description: string, quantity: string|int|float, unit_price: string|int|float}>  $lines
     */
    private function store(CustomsDossier $dossier, array $attributes, array $lines): CustomsQuote
    {
        return DB::transaction(function () use ($dossier, $attributes, $lines): CustomsQuote {
            $quote = CustomsQuote::query()->create([
                ...$attributes,
                'dossier_id' => $dossier->id,
                'quote_number' => $this->numbering->next('freight_quote')['number'],
                'status' => QuoteStatus::Draft,
            ]);

            $total = '0';

            foreach ($lines as $line) {
                $lineTotal = Decimal::round2(Decimal::mul((string) $line['quantity'], (string) $line['unit_price']));
                $total = Decimal::add($total, $lineTotal);
                $quote->lines()->create([
                    'description' => $line['description'],
                    'quantity' => (string) $line['quantity'],
                    'unit_price' => (string) $line['unit_price'],
                    'line_total' => $lineTotal,
                ]);
            }

            $quote->update(['total_amount' => Decimal::round2($total)]);

            return $quote->load('lines');
        });
    }

    private function gridFor(CustomsDossier $dossier, ?string $tariffGridId): CustomsTariffGrid
    {
        $today = now()->toDateString();

        $grid = CustomsTariffGrid::query()
            ->where('is_active', true)
            ->where('service_type', $dossier->service_type)
            ->where('currency', $dossier->currency)
            ->when($tariffGridId !== null, static fn ($query) => $query->whereKey($tariffGridId))
            ->where(static fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $today))
            ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today))
            ->first();

        return $grid ?? throw new CustomsException('No tariff grid of this service and currency is valid today.');
    }

    /**
     * The narrowest rate that fits: a rate for the destination goes before a rate for any destination.
     */
    private function rateFor(CustomsTariffGrid $grid, CustomsDossier $dossier): CustomsTariffGridRate
    {
        /** @var Collection<int, CustomsTariffGridRate> $rates */
        $rates = $grid->rates()->get()->filter(static function (CustomsTariffGridRate $rate) use ($dossier): bool {
            $weight = (string) $dossier->gross_weight_kg;
            $volume = (string) $dossier->volume_m3;

            return ($rate->destination_country === null || $rate->destination_country === $dossier->destination_country)
                && Decimal::compare($weight, (string) $rate->min_weight_kg) >= 0
                && ($rate->max_weight_kg === null || Decimal::compare($weight, (string) $rate->max_weight_kg) <= 0)
                && Decimal::compare($volume, (string) $rate->min_volume_m3) >= 0
                && ($rate->max_volume_m3 === null || Decimal::compare($volume, (string) $rate->max_volume_m3) <= 0);
        });

        $rate = $rates->sortBy(static fn (CustomsTariffGridRate $rate): int => $rate->destination_country === null ? 1 : 0)->first();

        return $rate ?? throw new CustomsException('The tariff grid has no rate for this weight, volume and destination.');
    }

    private function priceOf(CustomsTariffGridRate $rate, CustomsDossier $dossier): string
    {
        $price = match ($rate->basis) {
            RateBasis::Flat => (string) $rate->rate,
            RateBasis::PerKg => Decimal::mul((string) $dossier->gross_weight_kg, (string) $rate->rate),
            RateBasis::PerM3 => Decimal::mul((string) $dossier->volume_m3, (string) $rate->rate),
        };

        return Decimal::compare($price, (string) $rate->minimum_charge) < 0 ? (string) $rate->minimum_charge : $price;
    }
}
