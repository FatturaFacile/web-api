<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Services\Regulatory;

use Carbon\CarbonImmutable;
use Modules\DairyCheeseManufacturing\Domain\Enums\Authority;
use Modules\DairyCheeseManufacturing\Models\Breeder;
use XMLWriter;

/**
 * Writes the declaration files: the DBMilch.ch / TSM monthly delivery and milk utilisation report and the SIAN Registro
 * Telematico Latte movements (D.M. 27/05/2021), as XML or CSV.
 */
final class DeclarationWriter
{
    private const array SianTypes = ['milk_intake' => 'CARICO', 'milk_transformed' => 'LAVORAZIONE', 'cheese_produced' => 'PRODUZIONE', 'cheese_sold' => 'SCARICO'];

    private const array TsmTypes = ['milk_intake' => 'Lieferung', 'milk_transformed' => 'Verkaesung'];

    /**
     * @param  list<array{date: string, type: string, description: string, quantity_kg: string, reference: string, breeder_code?: string|null}>  $movements
     * @param  array<string, Breeder>  $breeders
     * @param  array<string, string|int>  $totals
     */
    public function xml(Authority $authority, string $period, string $tenantName, array $movements, array $breeders, array $totals): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $byCode = [];

        foreach ($breeders as $breeder) {
            $byCode[$breeder->code] = $breeder;
        }

        if ($authority === Authority::Dbmilch) {
            $xml->startElement('TSMMilchmeldung');
            $xml->writeAttribute('version', '1.0');
            $xml->writeAttribute('periode', $period);
            $xml->writeAttribute('betrieb', $tenantName);
            $xml->writeAttribute('erstellt', CarbonImmutable::now()->toIso8601String());
            $xml->startElement('Produzenten');

            foreach ($byCode as $code => $breeder) {
                $code = (string) $code;
                $xml->startElement('Produzent');
                $xml->writeAttribute('code', $code);
                $xml->writeAttribute('bdm', (string) $breeder->bdm_number);
                $xml->writeAttribute('tvd', (string) $breeder->tvd_number);
                $xml->writeAttribute('name', $breeder->name);
                $own = array_values(array_filter($movements, static fn (array $m): bool => $m['type'] === 'milk_intake' && ($m['breeder_code'] ?? null) === $code));
                $xml->writeAttribute('milch_total_kg', number_format(array_sum(array_map(static fn (array $m): float => (float) $m['quantity_kg'], $own)), 3, '.', ''));

                foreach ($own as $m) {
                    $xml->startElement('Lieferung');
                    $xml->writeAttribute('datum', $m['date']);
                    $xml->writeAttribute('kg', $m['quantity_kg']);
                    $xml->endElement();
                }

                $xml->endElement();
            }

            $xml->endElement();
            $xml->startElement('Verkaeste_Milch');
            $xml->writeAttribute('total_kg', (string) $totals['cheese_milk_kg']);

            foreach ($movements as $m) {
                if ($m['type'] === 'milk_transformed') {
                    $xml->startElement('Verkaesung');
                    $xml->writeAttribute('charge', $m['reference']);
                    $xml->writeAttribute('kaesetyp', $m['description']);
                    $xml->writeAttribute('datum', $m['date']);
                    $xml->writeAttribute('milch_kg', $m['quantity_kg']);
                    $xml->endElement();
                }
            }

            $xml->endElement();
            $xml->startElement('Summen');
            $xml->writeAttribute('milch_geliefert_kg', (string) $totals['milk_delivered_kg']);
            $xml->writeAttribute('verkaeste_milch_kg', (string) $totals['cheese_milk_kg']);
            $xml->writeAttribute('produzenten', (string) $totals['producers']);
            $xml->endElement();
            $xml->endElement();
        } else {
            $xml->startElement('RegistroTelematicoLatte');
            $xml->writeAttribute('riferimentoNormativo', 'D.M. 27/05/2021');
            $xml->writeAttribute('periodo', $period);
            $xml->writeAttribute('operatore', $tenantName);
            $xml->writeAttribute('generato', CarbonImmutable::now()->toIso8601String());
            $xml->startElement('Movimenti');

            foreach ($movements as $m) {
                $breeder = $byCode[$m['breeder_code'] ?? ''] ?? null;
                $xml->startElement('Movimento');
                $xml->writeAttribute('tipo', self::SianTypes[$m['type']] ?? strtoupper($m['type']));
                $xml->writeAttribute('data', $m['date']);
                $xml->writeAttribute('riferimento', $m['reference']);
                $xml->writeAttribute('descrizione', $m['description']);

                if ($breeder !== null) {
                    $xml->writeAttribute('cuaa', (string) $breeder->cuaa);
                    $xml->writeAttribute('codiceAsl', (string) $breeder->asl_code);
                }

                $xml->writeAttribute('quantita', $m['quantity_kg']);
                $xml->writeAttribute('unitaMisura', 'kg');
                $xml->endElement();
            }

            $xml->endElement();
            $xml->startElement('Totali');
            $xml->writeAttribute('latteConferitoKg', (string) $totals['milk_delivered_kg']);
            $xml->writeAttribute('latteLavoratoKg', (string) $totals['cheese_milk_kg']);
            $xml->writeAttribute('formaggioProdottoKg', (string) $totals['cheese_produced_kg']);
            $xml->writeAttribute('formaggioVendutoKg', (string) $totals['cheese_sold_kg']);
            $xml->endElement();
            $xml->endElement();
        }

        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * @param  list<array{date: string, type: string, description: string, quantity_kg: string, reference: string, breeder_code?: string|null}>  $movements
     * @param  array<string, Breeder>  $breeders
     */
    public function csv(Authority $authority, array $movements, array $breeders): string
    {
        $byCode = [];

        foreach ($breeders as $breeder) {
            $byCode[$breeder->code] = $breeder;
        }

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $authority === Authority::Dbmilch ? ['typ', 'datum', 'referenz', 'produzent', 'bdm', 'tvd', 'menge_kg'] : ['tipo', 'data', 'riferimento', 'allevatore', 'cuaa', 'codice_asl', 'quantita_kg'], ';', '"', '');

        foreach ($movements as $m) {
            $breeder = $byCode[$m['breeder_code'] ?? ''] ?? null;
            $type = $authority === Authority::Dbmilch ? (self::TsmTypes[$m['type']] ?? $m['type']) : (self::SianTypes[$m['type']] ?? $m['type']);
            $row = $authority === Authority::Dbmilch
                ? [$type, $m['date'], $m['reference'], $breeder?->name, $breeder?->bdm_number, $breeder?->tvd_number, $m['quantity_kg']]
                : [$type, $m['date'], $m['reference'], $breeder?->name, $breeder?->cuaa, $breeder?->asl_code, $m['quantity_kg']];
            fputcsv($handle, array_map(static fn ($v): string => (string) $v, $row), ';', '"', '');
        }

        rewind($handle);

        return (string) stream_get_contents($handle);
    }
}
