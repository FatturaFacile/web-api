<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Services;

use Carbon\CarbonImmutable;
use Modules\Beekeeping\Exceptions\BeekeepingException;
use Modules\Beekeeping\Models\Apiary;
use Modules\Beekeeping\Models\CensusDeclaration;
use Modules\Beekeeping\Models\Hive;

/**
 * The annual overwintering census (1 November to 31 December by default): a snapshot of the productive hives and nuclei per apiary.
 */
final class CensusService
{
    /**
     * Builds (or rebuilds, while still a draft) the declaration of a year and country.
     */
    public function generate(int $year, string $country): CensusDeclaration
    {
        $existing = CensusDeclaration::query()->where('census_year', $year)->where('country', $country)->first();

        if ($existing !== null && $existing->status === 'submitted') {
            throw new BeekeepingException('The census of this year was already submitted.', 'census_submitted');
        }

        $window = (array) config("beekeeping.census.{$country}");
        $snapshot = [];
        $hives = 0;
        $nuclei = 0;

        foreach (Apiary::query()->where('country', $country)->where('is_active', true)->orderBy('code')->get() as $apiary) {
            $counts = Hive::query()->where('apiary_id', $apiary->id)->whereIn('status', ['active', 'orphaned', 'queenless_cell'])
                ->selectRaw('is_nucleus, COUNT(*) as total')->groupBy('is_nucleus')->pluck('total', 'is_nucleus');
            $full = (int) ($counts[0] ?? 0);
            $small = (int) ($counts[1] ?? 0);

            if ($full + $small === 0) {
                continue;
            }

            $hives += $full;
            $nuclei += $small;
            $snapshot[] = [
                'apiary_id' => $apiary->id,
                'code' => $apiary->code,
                'name' => $apiary->name,
                'registry_code' => $apiary->registry_code,
                'municipality' => $apiary->municipality,
                'province_canton' => $apiary->province_canton,
                'apiary_type' => $apiary->apiary_type,
                'latitude' => $apiary->latitude,
                'longitude' => $apiary->longitude,
                'hives' => $full,
                'nuclei' => $small,
            ];
        }

        $declaration = $existing ?? new CensusDeclaration(['census_year' => $year, 'country' => $country]);
        $declaration->fill([
            'status' => 'draft',
            'window_start' => CarbonImmutable::parse("{$year}-".($window['start'] ?? '11-01'))->toDateString(),
            'window_end' => CarbonImmutable::parse("{$year}-".($window['end'] ?? '12-31'))->toDateString(),
            'total_hives' => $hives,
            'total_nuclei' => $nuclei,
            'snapshot' => $snapshot,
            'generated_at' => now(),
        ]);
        $declaration->save();

        return $declaration;
    }

    public function submit(CensusDeclaration $declaration, ?string $reference): CensusDeclaration
    {
        if ($declaration->status === 'submitted') {
            throw new BeekeepingException('The census of this year was already submitted.', 'census_submitted');
        }

        $declaration->status = 'submitted';
        $declaration->submitted_at = now();
        $declaration->submission_reference = $reference;
        $declaration->save();

        return $declaration;
    }

    public function isWithinWindow(CensusDeclaration $declaration, ?CarbonImmutable $today = null): bool
    {
        $today ??= CarbonImmutable::today();

        return $today->betweenIncluded(CarbonImmutable::parse($declaration->window_start), CarbonImmutable::parse($declaration->window_end)->endOfDay());
    }

    public function csv(CensusDeclaration $declaration): string
    {
        $rows = [['census_year', 'country', 'apiary_code', 'registry_code', 'name', 'municipality', 'province_canton', 'apiary_type', 'hives', 'nuclei']];

        foreach ($declaration->snapshot as $apiary) {
            $rows[] = [$declaration->census_year, $declaration->country, $apiary['code'], $apiary['registry_code'], $apiary['name'], $apiary['municipality'], $apiary['province_canton'], $apiary['apiary_type'], $apiary['hives'], $apiary['nuclei']];
        }

        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn (mixed $value): string => (string) $value, $row), ',', '"', '\\');
        }

        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    public function xml(CensusDeclaration $declaration): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><BeekeepingCensus/>');
        $xml->addAttribute('year', (string) $declaration->census_year);
        $xml->addAttribute('country', $declaration->country);
        $xml->addAttribute('totalHives', (string) $declaration->total_hives);
        $xml->addAttribute('totalNuclei', (string) $declaration->total_nuclei);

        foreach ($declaration->snapshot as $apiary) {
            $node = $xml->addChild('Apiary');
            $node->addAttribute('code', (string) $apiary['code']);
            $node->addChild('RegistryCode', htmlspecialchars((string) $apiary['registry_code']));
            $node->addChild('Name', htmlspecialchars((string) $apiary['name']));
            $node->addChild('Municipality', htmlspecialchars((string) $apiary['municipality']));
            $node->addChild('ProvinceCanton', htmlspecialchars((string) $apiary['province_canton']));
            $node->addChild('Type', (string) $apiary['apiary_type']);
            $node->addChild('Hives', (string) $apiary['hives']);
            $node->addChild('Nuclei', (string) $apiary['nuclei']);
        }

        return (string) $xml->asXML();
    }
}
