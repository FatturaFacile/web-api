<?php

declare(strict_types=1);

namespace Modules\Customs\Services;

use Illuminate\Support\Str;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Models\CustomsCountry;
use Modules\Customs\Models\CustomsDocumentType;
use Modules\Customs\Models\CustomsMasterRecord;
use Modules\Customs\Models\CustomsPackagingType;
use Modules\Customs\Models\CustomsStatisticalKey;
use Modules\Customs\Models\CustomsTariffNumber;
use XMLReader;

/**
 * Loads the official e-dec master data files of BAZG as generic rows. The files are read as they are published: a code
 * with several validity periods keeps the one ending last. Company rows are never touched.
 */
final class CustomsMasterDataImporter
{
    private const string SOURCE = 'bazg_edec';

    private const int BATCH = 500;

    /**
     * edecDomains.xml: the packaging types and the document types.
     *
     * @return array{packaging_types: int, document_types: int}
     */
    public function importDomains(string $path): array
    {
        $entries = ['packagingType' => [], 'documentType' => []];
        $reader = $this->open($path);
        $domain = null;

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->name === 'domain') {
                $domain = $reader->getAttribute('name');
            } elseif ($reader->name === 'entry' && isset($entries[$domain])) {
                $this->keepLatest($entries[$domain], $this->row($reader, 'value', 'meaning'));
            }
        }

        $reader->close();

        return [
            'packaging_types' => $this->save(CustomsPackagingType::class, $entries['packagingType'], ['code']),
            'document_types' => $this->save(CustomsDocumentType::class, $entries['documentType'], ['code']),
        ];
    }

    /**
     * edecCountryCodes.xml: the countries.
     */
    public function importCountries(string $path): int
    {
        $rows = [];
        $reader = $this->open($path);

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'country') {
                $this->keepLatest($rows, $this->row($reader, 'isoCode', 'name'));
            }
        }

        $reader->close();

        return $this->save(CustomsCountry::class, $rows, ['code']);
    }

    /**
     * edecTariffMasterData.xml: the tariff numbers and their statistical keys. The file is large, so it is streamed;
     * it holds no description of the tariff numbers, and rates are not imported.
     *
     * @return array{tariff_numbers: int, statistical_keys: int}
     */
    public function importTariff(string $path): array
    {
        $numbers = [];
        $keys = [];
        $reader = $this->open($path);
        $current = null;

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->name === 'commodityCode') {
                $current = (string) $reader->getAttribute('value');
                $this->keepLatest($numbers, $this->row($reader, 'value', 'meaning'));
            } elseif ($reader->name === 'statisticalCode' && $current !== null) {
                $row = ['tariff_code' => $current] + $this->row($reader, 'value', 'meaning');
                $id = $current.'|'.$row['code'];
                $existing = $keys[$id] ?? null;

                if ($existing === null || (string) $row['valid_to'] >= (string) $existing['valid_to']) {
                    $keys[$id] = $row;
                }
            }
        }

        $reader->close();

        return [
            'tariff_numbers' => $this->save(CustomsTariffNumber::class, $numbers, ['code']),
            'statistical_keys' => $this->save(CustomsStatisticalKey::class, $keys, ['tariff_code', 'code']),
        ];
    }

    /**
     * @throws CustomsException when the file cannot be read
     */
    private function open(string $path): XMLReader
    {
        $reader = is_file($path) ? XMLReader::open($path, null, LIBXML_NONET) : false;

        if ($reader === false) {
            throw new CustomsException("The master data file {$path} cannot be read.");
        }

        return $reader;
    }

    /**
     * @return array<string, string|null>
     */
    private function row(XMLReader $reader, string $codeAttribute, string $namePrefix): array
    {
        return [
            'code' => (string) $reader->getAttribute($codeAttribute),
            'name_de' => $this->name($reader, $namePrefix.'De'),
            'name_fr' => $this->name($reader, $namePrefix.'Fr'),
            'name_it' => $this->name($reader, $namePrefix.'It'),
            'name_en' => $this->name($reader, $namePrefix.'En'),
            'valid_from' => $reader->getAttribute('validFrom'),
            'valid_to' => $reader->getAttribute('validTo'),
        ];
    }

    private function name(XMLReader $reader, string $attribute): ?string
    {
        $value = $reader->getAttribute($attribute);

        return $value === null ? null : mb_substr(trim($value), 0, 500);
    }

    /**
     * @param  array<string, array<string, string|null>>  $rows
     * @param  array<string, string|null>  $row
     */
    private function keepLatest(array &$rows, array $row): void
    {
        $existing = $rows[$row['code']] ?? null;

        if ($existing === null || (string) $row['valid_to'] >= (string) $existing['valid_to']) {
            $rows[$row['code']] = $row;
        }
    }

    /**
     * @param  class-string<CustomsMasterRecord>  $model
     * @param  array<string, array<string, string|null>>  $rows
     * @param  list<string>  $keys
     */
    private function save(string $model, array $rows, array $keys): int
    {
        $now = now();
        $query = $model::query()->withoutGlobalScopes();

        foreach (array_chunk(array_values($rows), self::BATCH) as $chunk) {
            $values = array_map(static fn (array $row): array => $row + [
                'id' => (string) Str::uuid7(),
                'tenant_id' => null,
                'is_active' => true,
                'source' => self::SOURCE,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk);

            $columns = array_values(array_diff(array_keys($values[0]), ['id', 'tenant_id', 'created_at', ...$keys]));
            $query->upsert($values, ['tenant_id', ...$keys], $columns);
        }

        return count($rows);
    }
}
