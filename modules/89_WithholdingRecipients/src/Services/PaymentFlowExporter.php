<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Services;

use App\Services\Tenancy\TenantContext;
use DOMDocument;
use Illuminate\Support\Collection;
use Modules\WithholdingRecipients\Domain\ValueObjects\Money;
use Modules\WithholdingRecipients\Models\RecipientSettlement;

/**
 * Payment flows fed by the posted settlements of a month (cash principle): the Modello F24 (1040, 1038, C10) due on the 16th
 * of the following month, and the UniEmens Gestione Separata flow of the months where INPS contributions are due.
 */
final class PaymentFlowExporter
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @return array{month: string, due_date: string|null, lines: list<array{section: string, tax_code: string, reference_month: string, reference_year: int, amount: string, settlements: int}>, total: string}
     */
    public function f24Preview(string $month): array
    {
        $settlements = $this->monthSettlements($month)->where('jurisdiction', 'IT');
        $grouped = [];

        foreach ($settlements as $settlement) {
            foreach ((array) $settlement->f24_lines as $line) {
                $key = $line['section'].'|'.$line['tax_code'].'|'.$line['reference_month'].'|'.$line['reference_year'];
                $grouped[$key] ??= ['section' => $line['section'], 'tax_code' => $line['tax_code'], 'reference_month' => $line['reference_month'], 'reference_year' => (int) $line['reference_year'], 'cents' => 0, 'settlements' => 0];
                $grouped[$key]['cents'] += Money::of($line['amount'])->cents;
                $grouped[$key]['settlements']++;
            }
        }

        ksort($grouped);
        $lines = array_values(array_map(static fn (array $g): array => [
            'section' => $g['section'],
            'tax_code' => $g['tax_code'],
            'reference_month' => $g['reference_month'],
            'reference_year' => $g['reference_year'],
            'amount' => Money::fromCents($g['cents'])->toDecimal(),
            'settlements' => $g['settlements'],
        ], $grouped));

        return [
            'month' => $month,
            'due_date' => $settlements->pluck('f24_due_date')->filter()->map(static fn ($d): string => $d->toDateString())->sort()->first(),
            'lines' => $lines,
            'total' => Money::fromCents((int) array_sum(array_map(static fn (array $g): int => $g['cents'], $grouped)))->toDecimal(),
        ];
    }

    public function f24Csv(string $month): string
    {
        $preview = $this->f24Preview($month);
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['section', 'tax_code', 'reference_month', 'reference_year', 'amount_debit', 'due_date'], ';', '"', '');

        foreach ($preview['lines'] as $line) {
            fputcsv($handle, [$line['section'], $line['tax_code'], $line['reference_month'], $line['reference_year'], $line['amount'], $preview['due_date']], ';', '"', '');
        }

        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    /**
     * UniEmens (Gestione Separata) flow of the month: one PosGS entry per compensation with INPS contributions.
     */
    public function uniEmensXml(string $month): string
    {
        $tenant = $this->tenantContext->getTenant();
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElement('UniEmens');
        $document->appendChild($root);
        $root->appendChild($document->createElement('Periodo', $month));
        $client = $document->createElement('Committente');
        $client->appendChild($document->createElement('Denominazione', htmlspecialchars((string) ($tenant->legal_name ?? $tenant->name ?? ''), ENT_XML1)));
        $client->appendChild($document->createElement('CodiceFiscale', (string) ($tenant->fiscal_code ?? '')));
        $root->appendChild($client);
        $list = $document->createElement('ListaPosGS');
        $root->appendChild($list);

        foreach ($this->monthSettlements($month)->where('uniemens_required', true) as $settlement) {
            $entry = $document->createElement('PosGS');
            $entry->appendChild($document->createElement('CodiceFiscale', (string) $settlement->profile?->tax_code));
            $entry->appendChild($document->createElement('Nominativo', htmlspecialchars((string) $settlement->profile?->display_name, ENT_XML1)));
            $entry->appendChild($document->createElement('TipoRapporto', $settlement->regime === 'amateur_sports' ? 'SportDilettantistico' : 'LavoroAutonomoOccasionale'));
            $entry->appendChild($document->createElement('DataPagamento', $settlement->payment_date->toDateString()));
            $entry->appendChild($document->createElement('Compenso', $settlement->gross_amount));
            $entry->appendChild($document->createElement('ImponibileContributivo', $settlement->inps_base));
            $entry->appendChild($document->createElement('ContributoTotale', $settlement->inps_total));
            $entry->appendChild($document->createElement('QuotaLavoratore', $settlement->inps_worker));
            $entry->appendChild($document->createElement('QuotaCommittente', $settlement->inps_company));
            $list->appendChild($entry);
        }

        return (string) $document->saveXML();
    }

    /**
     * @return Collection<int, RecipientSettlement>
     */
    private function monthSettlements(string $month): Collection
    {
        return RecipientSettlement::query()->with('profile')->where('accrual_month', $month)->where('status', 'posted')->orderBy('payment_date')->get();
    }
}
