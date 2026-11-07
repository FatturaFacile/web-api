<?php

declare(strict_types=1);

namespace Modules\Intrastat\Services;

use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Intrastat\Enums\IntrastatFlow;
use Modules\Intrastat\Enums\SupplyKind;
use Modules\Intrastat\Models\IntrastatProductProfile;
use Modules\Intrastat\Support\IntrastatCodes;
use Modules\SDI\Enums\NaturaIva;
use Modules\SDI\Enums\TipoDocumento;
use ValueError;

/**
 * Reads the invoices of the active company that concern other member states and turns them into entries the lists are
 * drafted from. Sales are the issued invoices to customers of another member state; purchases the received invoices of
 * suppliers of another member state.
 *
 * Goods or services:
 * - sales: the Intrastat profile of the product; otherwise an invoice with VAT nature N3.2 (non imponibili - cessioni
 *   intracomunitarie, NaturaType of the FatturaPA schema, see Modules\SDI\Enums\NaturaIva) is a sale of goods;
 * - purchases: the integration document registered for the received invoice, TD18 (integrazione per acquisto di beni
 *   intracomunitari) for goods and TD17 (integrazione/autofattura per acquisto servizi dall'estero) for services, see
 *   Modules\SDI\Enums\TipoDocumento.
 * Anything else is reported as unclassified, unless the caller states how to treat it. Nothing is guessed.
 *
 * @phpstan-type Entry array{
 *     kind: SupplyKind,
 *     partner_country: string,
 *     partner_vat: string|null,
 *     amount_eur: string|null,
 *     amount_currency: string|null,
 *     product_id: string|null,
 *     cn8: string|null,
 *     net_mass: string|null,
 *     supplementary_units: string|null,
 *     service_code: string|null,
 *     origin_country: string|null,
 *     origin_province: string|null,
 *     line_key: string,
 *     document: array{type: string, id: string, number: string|null, date: string|null}
 * }
 * @phpstan-type Notice array{document: array{type: string, id: string, number: string|null, date: string|null}, reason: string, detail?: string, amount?: string}
 */
final class IntrastatSourceCollector
{
    /**
     * @return array{entries: list<Entry>, skipped: list<Notice>, unclassified: list<Notice>, warnings: list<Notice>}
     */
    public function collect(IntrastatFlow $flow, Carbon $from, Carbon $to, ?SupplyKind $unclassifiedAs = null): array
    {
        return $flow === IntrastatFlow::Sales
            ? $this->sales($from, $to, $unclassifiedAs)
            : $this->purchases($from, $to, $unclassifiedAs);
    }

    /**
     * @return array{entries: list<Entry>, skipped: list<Notice>, unclassified: list<Notice>, warnings: list<Notice>}
     */
    private function sales(Carbon $from, Carbon $to, ?SupplyKind $unclassifiedAs): array
    {
        $result = ['entries' => [], 'skipped' => [], 'unclassified' => [], 'warnings' => []];

        $invoices = Invoice::query()
            ->with(['contact', 'items'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('invoice_type', '!=', 'proforma')
            ->orderBy('date')
            ->orderBy('invoice_number')
            ->get();

        $productIds = $invoices->flatMap(static fn (Invoice $invoice) => $invoice->items->pluck('product_id'))->filter()->unique()->values();
        $profiles = IntrastatProductProfile::query()->whereIn('product_id', $productIds)->get()->keyBy('product_id');
        $products = Product::withTrashed()->whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($invoices as $invoice) {
            $type = $this->documentType($invoice);

            if ($type?->isSelfInvoice() === true) {
                continue;
            }

            $country = IntrastatCodes::memberState($invoice->contact?->country);

            if ($country === null || $country === IntrastatCodes::Italy) {
                continue;
            }

            $document = ['type' => 'invoice', 'id' => (string) $invoice->id, 'number' => $invoice->invoice_number, 'date' => $invoice->date?->toDateString()];

            if ($invoice->invoice_type === 'credit_note' || $type === TipoDocumento::Td04) {
                $result['skipped'][] = ['document' => $document, 'reason' => 'credit_note', 'detail' => 'A credit note corrects an earlier period: enter it as a correction row (section 2 or 4).'];

                continue;
            }

            $vatCode = IntrastatCodes::vatCode($invoice->contact?->vat_number, $country);

            if ($vatCode === null) {
                $result['skipped'][] = ['document' => $document, 'reason' => 'customer_without_vat_number', 'detail' => 'The INTRA-1 lists report operations with parties identified for VAT in another member state.'];

                continue;
            }

            $isEuro = strtoupper((string) ($invoice->currency ?: 'EUR')) === 'EUR';

            if (! $isEuro) {
                $result['warnings'][] = ['document' => $document, 'reason' => 'foreign_currency', 'detail' => 'The invoice is not in euro: enter the amount in euro by hand.'];
            }

            $lines = $invoice->items->isEmpty()
                ? [['id' => (string) $invoice->id, 'product_id' => null, 'description' => null, 'quantity' => '1', 'amount' => (string) ($invoice->total_net ?? '0')]]
                : $invoice->items->map(static fn ($item): array => ['id' => (string) $item->id, 'product_id' => $item->product_id, 'description' => $item->description, 'quantity' => (string) ($item->quantity ?? '1'), 'amount' => (string) ($item->line_total ?? '0')])->all();

            foreach ($lines as $line) {
                $profile = $line['product_id'] !== null ? $profiles->get($line['product_id']) : null;
                $kind = $profile?->supply_kind
                    ?? ($invoice->vat_nature === NaturaIva::N32->value ? SupplyKind::Goods : null)
                    ?? $unclassifiedAs;

                if ($kind === null) {
                    $result['unclassified'][] = ['document' => $document, 'reason' => 'unclassified', 'detail' => (string) ($line['description'] ?? 'Invoice without lines'), 'amount' => Decimal::round2($line['amount'])];

                    continue;
                }

                $product = $line['product_id'] !== null ? $products->get($line['product_id']) : null;

                $result['entries'][] = [
                    'kind' => $kind,
                    'partner_country' => $country,
                    'partner_vat' => $vatCode,
                    'amount_eur' => $isEuro ? $line['amount'] : null,
                    'amount_currency' => $isEuro ? null : $line['amount'],
                    'product_id' => $line['product_id'],
                    'cn8' => $profile?->cn8_code ?? $this->combinedNomenclatureOf($product?->hs_code),
                    'net_mass' => $profile?->net_mass_kg !== null ? Decimal::mul((string) $profile->net_mass_kg, $line['quantity']) : null,
                    'supplementary_units' => $profile?->supplementary_units_per_unit !== null ? Decimal::mul((string) $profile->supplementary_units_per_unit, $line['quantity']) : null,
                    'service_code' => $profile?->service_code,
                    'origin_country' => $profile?->origin_country_code,
                    'origin_province' => $profile?->origin_province_code,
                    'line_key' => $line['product_id'] !== null ? 'product:'.$line['product_id'] : 'line:'.$line['id'],
                    'document' => $document,
                ];
            }
        }

        return $result;
    }

    /**
     * @return array{entries: list<Entry>, skipped: list<Notice>, unclassified: list<Notice>, warnings: list<Notice>}
     */
    private function purchases(Carbon $from, Carbon $to, ?SupplyKind $unclassifiedAs): array
    {
        $result = ['entries' => [], 'skipped' => [], 'unclassified' => [], 'warnings' => []];

        $invoices = PassiveInvoice::query()
            ->with(['supplier', 'lines'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->orderBy('invoice_number')
            ->get();

        $integrations = Invoice::query()
            ->whereIn('source_passive_invoice_id', $invoices->pluck('id'))
            ->whereIn('document_type', [TipoDocumento::Td17->value, TipoDocumento::Td18->value])
            ->pluck('document_type', 'source_passive_invoice_id');

        foreach ($invoices as $invoice) {
            $country = IntrastatCodes::memberState($invoice->supplier?->country);

            if ($country === null || $country === IntrastatCodes::Italy) {
                continue;
            }

            $document = ['type' => 'passive_invoice', 'id' => (string) $invoice->id, 'number' => $invoice->invoice_number, 'date' => $invoice->date?->toDateString()];

            if ($invoice->document_type === TipoDocumento::Td04->value) {
                $result['skipped'][] = ['document' => $document, 'reason' => 'credit_note', 'detail' => 'A credit note corrects an earlier period: enter it as a correction row (section 2 or 4).'];

                continue;
            }

            $amount = $invoice->lines->isEmpty()
                ? (string) ($invoice->total_net ?? $invoice->total_amount ?? '0')
                : $invoice->lines->reduce(static fn (string $sum, $line): string => Decimal::add($sum, (string) ($line->line_total ?? '0')), '0');

            $kind = match ($integrations->get($invoice->id)) {
                TipoDocumento::Td18->value => SupplyKind::Goods,
                TipoDocumento::Td17->value => SupplyKind::Services,
                default => $unclassifiedAs,
            };

            if ($kind === null) {
                $result['unclassified'][] = ['document' => $document, 'reason' => 'unclassified', 'detail' => 'No TD17 or TD18 integration document is registered for this invoice.', 'amount' => Decimal::round2($amount)];

                continue;
            }

            $isEuro = strtoupper((string) ($invoice->currency ?: 'EUR')) === 'EUR';

            if (! $isEuro) {
                $result['warnings'][] = ['document' => $document, 'reason' => 'foreign_currency', 'detail' => 'The invoice is not in euro: enter the amount in euro by hand.'];
            }

            $result['entries'][] = [
                'kind' => $kind,
                'partner_country' => $country,
                'partner_vat' => IntrastatCodes::vatCode($invoice->supplier?->vat_number, $country),
                'amount_eur' => $isEuro ? $amount : null,
                'amount_currency' => $isEuro ? null : $amount,
                'product_id' => null,
                'cn8' => null,
                'net_mass' => null,
                'supplementary_units' => null,
                'service_code' => null,
                'origin_country' => null,
                'origin_province' => null,
                'line_key' => 'passive_invoice:'.$invoice->id,
                'document' => $document,
            ];
        }

        return $result;
    }

    private function documentType(Invoice $invoice): ?TipoDocumento
    {
        try {
            return TipoDocumento::ofInvoice($invoice);
        } catch (ValueError) {
            return null;
        }
    }

    /**
     * The customs code of the product, when it is already an 8 digit combined nomenclature code.
     */
    private function combinedNomenclatureOf(?string $customsCode): ?string
    {
        $digits = (string) preg_replace('/[\s.]/', '', (string) $customsCode);

        return preg_match('/^\d{8}$/', $digits) === 1 ? $digits : null;
    }
}
