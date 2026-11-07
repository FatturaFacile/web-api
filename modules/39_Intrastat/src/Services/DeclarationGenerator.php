<?php

declare(strict_types=1);

namespace Modules\Intrastat\Services;

use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Intrastat\Enums\DeclarationStatus;
use Modules\Intrastat\Enums\IntrastatFlow;
use Modules\Intrastat\Enums\Periodicity;
use Modules\Intrastat\Enums\SupplyKind;
use Modules\Intrastat\Models\IntrastatDeclaration;
use Modules\Intrastat\Support\IntrastatRounding;

/**
 * Drafts a list from the invoices of its period. Rows with the same characteristics are summed into one detail row, as
 * the instructions require (Allegato XI to determinazione ADM 493869/RU del 23/12/2021: Titolo II Sezione 1 note 3 and
 * Sezione 3 note 3, Titolo III Sezione 1 note 3 and Sezione 3 note 4). A row whose combined nomenclature or service code
 * is unknown stays on its own, so that the user can complete it; missing data are left empty and reported by the
 * validator, never filled with placeholders.
 *
 * Data the invoices do not hold come only from the defaults the user gives: nature of the transaction, delivery terms,
 * transport mode, province, country of payment and service code. The country of the partner is proposed as country of
 * destination (sales) or of provenance (purchases), which the user can change. The amount in foreign currency of the
 * purchase lists, optional from 2022, is not drafted: Allegato XI gives no rounding rule for it.
 */
final class DeclarationGenerator
{
    public function __construct(private readonly IntrastatSourceCollector $collector) {}

    /**
     * @param  array<string, mixed>  $attributes  frontespizio attributes of the new list
     * @param  array<string, string|null>  $defaults
     */
    public function generate(array $attributes, array $defaults = [], ?SupplyKind $unclassifiedAs = null): IntrastatDeclaration
    {
        return DB::transaction(function () use ($attributes, $defaults, $unclassifiedAs): IntrastatDeclaration {
            $declaration = new IntrastatDeclaration($attributes);
            $declaration->status = DeclarationStatus::Draft;
            $declaration->reference_number ??= self::nextReferenceNumber();

            [$from, $to] = $declaration->periodRange();
            $collected = $this->collector->collect($declaration->flow, $from, $to, $unclassifiedAs);

            $declaration->generation_report = [
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
                'documents' => count(array_unique(array_map(static fn (array $entry): string => $entry['document']['type'].':'.$entry['document']['id'], $collected['entries']))),
                'skipped' => $collected['skipped'],
                'unclassified' => $collected['unclassified'],
                'warnings' => $collected['warnings'],
            ];
            $declaration->save();

            $groups = [];

            foreach ($collected['entries'] as $entry) {
                $section = $entry['kind'] === SupplyKind::Goods ? 1 : 3;
                $groups[$section][$this->groupKey($declaration, $section, $entry, $defaults)][] = $entry;
            }

            ksort($groups);

            foreach ($groups as $section => $sectionGroups) {
                $lineNumber = 0;

                foreach ($sectionGroups as $entries) {
                    $declaration->lines()->create(['section' => $section, 'line_number' => ++$lineNumber, ...$this->row($declaration, $section, $entries, $defaults)]);
                }
            }

            return $declaration->load('lines');
        });
    }

    /**
     * The reference numbers follow one sequence per presenter across the years, whatever the list (Allegato XI,
     * frontespizio): the next one after the highest of the company.
     */
    public static function nextReferenceNumber(): int
    {
        return ((int) IntrastatDeclaration::query()->max('reference_number')) + 1;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, string|null>  $defaults
     */
    private function groupKey(IntrastatDeclaration $declaration, int $section, array $entry, array $defaults): string
    {
        if ($section === 1) {
            return implode('|', [
                $entry['partner_country'],
                $entry['partner_vat'],
                $entry['cn8'] ?? $entry['line_key'],
                $entry['origin_country'],
                $entry['origin_province'],
            ]);
        }

        if ($declaration->flow === IntrastatFlow::Sales) {
            return implode('|', [$entry['partner_country'], $entry['partner_vat'], $entry['service_code'] ?? ($defaults['service_code'] ?? null) ?? $entry['line_key']]);
        }

        return implode('|', [$entry['partner_country'], $entry['document']['id'], $entry['service_code'] ?? $defaults['service_code'] ?? '']);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, string|null>  $defaults
     * @return array<string, mixed>
     */
    private function row(IntrastatDeclaration $declaration, int $section, array $entries, array $defaults): array
    {
        $first = $entries[0];
        $documents = array_values(array_unique(array_map(static fn (array $entry): array => $entry['document'], $entries), SORT_REGULAR));

        $row = [
            'partner_country_code' => $first['partner_country'],
            'partner_vat_number' => $first['partner_vat'] !== null && strlen($first['partner_vat']) <= 12 ? $first['partner_vat'] : null,
            'amount_eur' => IntrastatRounding::euro($this->sum($entries, 'amount_eur')),
            'source_documents' => $documents,
        ];

        if ($section === 3) {
            $single = count($documents) === 1 ? $documents[0] : null;

            return [
                ...$row,
                'service_code' => $first['service_code'] ?? $defaults['service_code'] ?? null,
                'payment_country_code' => $defaults['payment_country_code'] ?? null,
                'invoice_number' => $single !== null && $single['number'] !== null && strlen((string) $single['number']) <= 15 ? $single['number'] : null,
                'invoice_date' => $single['date'] ?? null,
            ];
        }

        $row['transaction_nature_a'] = $defaults['transaction_nature_a'] ?? null;
        $row['cn8_code'] = $first['cn8'];

        if (! $declaration->requiresStatisticalData()) {
            return $row;
        }

        return [
            ...$row,
            'transaction_nature_b' => $declaration->has_annual_flows_above_20_million ? ($defaults['transaction_nature_b'] ?? null) : null,
            'net_mass_kg' => IntrastatRounding::netMass($this->sum($entries, 'net_mass')),
            'supplementary_units' => IntrastatRounding::units($this->sum($entries, 'supplementary_units')),
            'delivery_terms' => $defaults['delivery_terms'] ?? null,
            'transport_mode' => $defaults['transport_mode'] ?? null,
            'goods_country_code' => $first['partner_country'],
            'origin_country_code' => $first['origin_country'],
            'province_code' => $declaration->flow === IntrastatFlow::Sales ? ($first['origin_province'] ?? $defaults['province_code'] ?? null) : ($defaults['province_code'] ?? null),
        ];
    }

    /**
     * The sum of a value over the entries, or null when one of them lacks it.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function sum(array $entries, string $key): ?string
    {
        $total = '0';

        foreach ($entries as $entry) {
            if ($entry[$key] === null) {
                return null;
            }

            $total = Decimal::add($total, (string) $entry[$key]);
        }

        return $total;
    }

    /**
     * Whether the periodicity is allowed for the flow: the quarterly INTRA-2 lists are abolished from 2022
     * (determinazione ADM 493869/RU, art. 4; Allegato XI, Titolo III, frontespizio).
     */
    public static function allowsPeriodicity(IntrastatFlow $flow, Periodicity $periodicity): bool
    {
        return $flow === IntrastatFlow::Sales || $periodicity === Periodicity::Monthly;
    }
}
