<?php

declare(strict_types=1);

namespace Modules\Payroll\Swissdec;

use DOMDocument;
use DOMElement;

/**
 * Serializes the SalaryDeclarationBuilder array structure into well-formed Swissdec ELM 5.0 / 6.0 XML.
 */
final class SwissdecElmXmlSerializer
{
    /**
     * @param  array<string, mixed>  $declaration  Full output of SalaryDeclarationBuilder::build() or its SalaryDeclaration sub-array
     */
    public function toXml(array $declaration, string $elmVersion = '5.0'): string
    {
        $rootData = isset($declaration['SalaryDeclaration']) && is_array($declaration['SalaryDeclaration'])
            ? $declaration['SalaryDeclaration']
            : $declaration;

        $namespace = $elmVersion === '6.0'
            ? SwissdecCertificationStatus::Elm60SchemaNamespace
            : SwissdecCertificationStatus::SchemaNamespace;

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS($namespace, 'SalaryDeclaration');
        $root->setAttribute('schemaVersion', $elmVersion);
        $dom->appendChild($root);

        $this->appendChildren($dom, $root, $rootData, $namespace);

        return (string) $dom->saveXML();
    }

    /**
     * @param  array<string|int, mixed>  $data
     */
    private function appendChildren(DOMDocument $dom, DOMElement $parent, array $data, string $namespace): void
    {
        foreach ($data as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $tagName = (string) $key;

            if (is_array($value)) {
                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $child = $dom->createElementNS($namespace, $tagName);
                            $this->appendChildren($dom, $child, $item, $namespace);
                            $parent->appendChild($child);
                        } elseif ($item !== null && $item !== '') {
                            $child = $dom->createElementNS($namespace, $tagName);
                            $child->appendChild($dom->createTextNode($this->scalarToString($item)));
                            $parent->appendChild($child);
                        }
                    }
                } else {
                    $child = $dom->createElementNS($namespace, $tagName);
                    $this->appendChildren($dom, $child, $value, $namespace);

                    if ($child->hasChildNodes()) {
                        $parent->appendChild($child);
                    }
                }

                continue;
            }

            $child = $dom->createElementNS($namespace, $tagName);
            $child->appendChild($dom->createTextNode($this->scalarToString($value)));
            $parent->appendChild($child);
        }
    }

    private function scalarToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
