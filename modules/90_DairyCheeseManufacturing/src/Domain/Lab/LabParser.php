<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Lab;

use Modules\DairyCheeseManufacturing\Domain\Enums\LabFormat;
use SimpleXMLElement;

/**
 * Parser of the laboratory export files (Suisselab CSV, TSM XML, AIA CSV, ARAL XML and a generic CSV). Pure: text in, rows out.
 * XML is read without entity expansion or network access.
 */
final class LabParser
{
    public function __construct(private readonly LabRowNormalizer $normalizer = new LabRowNormalizer) {}

    /**
     * @return list<array{line: int, identifiers: array<string, string>, data: array<string, mixed>, errors: list<string>}>
     */
    public function parse(string $content, LabFormat $format): array
    {
        $profile = LabFormatProfile::for($format);
        $content = ltrim($content, "\xEF\xBB\xBF");

        return $profile->xml ? $this->parseXml($content, $profile) : $this->parseCsv($content, $profile);
    }

    /**
     * @return list<array{line: int, identifiers: array<string, string>, data: array<string, mixed>, errors: list<string>}>
     */
    private function parseCsv(string $content, LabFormatProfile $profile): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($content)) ?: [];

        if ($lines === [] || trim($lines[0]) === '') {
            return [];
        }

        $delimiter = $profile->delimiter ?? (substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',');
        $headers = array_map(fn (string $h): string => $this->key($h), str_getcsv($lines[0], $delimiter, '"', ''));
        $rows = [];

        foreach (array_slice($lines, 1, null, true) as $index => $text) {
            if (trim($text) === '') {
                continue;
            }

            $cells = str_getcsv($text, $delimiter, '"', '');
            $raw = [];

            foreach ($headers as $column => $header) {
                if (isset($profile->fields[$header])) {
                    $raw[$profile->fields[$header]] = (string) ($cells[$column] ?? '');
                }
            }

            $rows[] = $this->normalizer->normalize($raw, $profile, $index + 1);
        }

        return $rows;
    }

    /**
     * @return list<array{line: int, identifiers: array<string, string>, data: array<string, mixed>, errors: list<string>}>
     */
    private function parseXml(string $content, LabFormatProfile $profile): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return [['line' => 1, 'identifiers' => [], 'data' => [], 'errors' => ['xml_invalid']]];
        }

        $rows = [];
        $line = 0;

        foreach ($xml->children() as $sample) {
            $line++;
            $raw = [];

            foreach ($sample->attributes() ?? [] as $name => $value) {
                $this->collect($profile, (string) $name, (string) $value, $raw);
            }

            foreach ($sample->children() as $name => $child) {
                $this->collect($profile, (string) $name, trim((string) $child), $raw);

                foreach ($child->attributes() ?? [] as $attribute => $value) {
                    $this->collect($profile, (string) $name.(string) $attribute, (string) $value, $raw);
                }
            }

            $rows[] = $this->normalizer->normalize($raw, $profile, $line);
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $raw
     */
    private function collect(LabFormatProfile $profile, string $name, string $value, array &$raw): void
    {
        $key = $this->key($name);

        if (isset($profile->fields[$key]) && $value !== '') {
            $raw[$profile->fields[$key]] = $value;
        }
    }

    private function key(string $header): string
    {
        $ascii = strtr(strtolower(trim($header)), ['ß' => 'ss', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);

        return (string) preg_replace('/[^a-z0-9]/', '', $ascii);
    }
}
