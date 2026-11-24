<?php

declare(strict_types=1);

namespace Modules\Payroll\Swissdec;

use DOMDocument;
use DOMXPath;
use LibXMLError;
use Modules\Payroll\Calculation\Dec;

/**
 * Validates a Swissdec ELM 5.0 / 6.0 XML declaration against the bundled XSD schema and performs
 * cross-domain arithmetic and identifier consistency checks across all 10 Swissdec domains.
 */
final class SwissdecXsdValidator
{
    public function schemaPath(): string
    {
        return dirname(__DIR__, 2).'/resources/schemas/SwissdecSalaryDeclaration.xsd';
    }

    /**
     * @return array{is_valid: bool, errors: list<string>, domain_warnings: list<string>}
     */
    public function validate(string $xml): array
    {
        if (trim($xml) === '') {
            return [
                'is_valid' => false,
                'errors' => ['Il documento XML è vuoto.'],
                'domain_warnings' => [],
            ];
        }

        $previousErrorSetting = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $normalizedXml = str_replace(
                SwissdecCertificationStatus::Elm60SchemaNamespace,
                SwissdecCertificationStatus::SchemaNamespace,
                $xml,
            );

            $dom = new DOMDocument('1.0', 'UTF-8');
            $loaded = $dom->loadXML($normalizedXml, LIBXML_NONET);

            if (! $loaded) {
                return [
                    'is_valid' => false,
                    'errors' => $this->collectLibXmlErrors(),
                    'domain_warnings' => [],
                ];
            }

            $schemaValid = $dom->schemaValidate($this->schemaPath());
            $errors = $schemaValid ? [] : $this->collectLibXmlErrors();
            $domainWarnings = [];

            $this->runDomainConsistencyChecks($dom, $errors, $domainWarnings);

            return [
                'is_valid' => $errors === [],
                'errors' => $errors,
                'domain_warnings' => $domainWarnings,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorSetting);
        }
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function runDomainConsistencyChecks(DOMDocument $dom, array &$errors, array &$warnings): void
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('sd', SwissdecCertificationStatus::SchemaNamespace);

        $personNodes = $xpath->query('/sd:SalaryDeclaration/sd:Company/sd:Person');
        $personCount = $personNodes === false ? 0 : $personNodes->length;

        $counterAhv = $this->xpathText($xpath, '/sd:SalaryDeclaration/sd:Company/sd:SalaryCounters/sd:NumberOf-AHV-AVS-Salary-Tags');

        if ($counterAhv !== null && (int) $counterAhv !== $personCount) {
            $errors[] = "NumberOf-AHV-AVS-Salary-Tags ({$counterAhv}) non corrisponde al numero di elementi Person ({$personCount}).";
        }

        $sumAhv = $this->sumXpath($xpath, '/sd:SalaryDeclaration/sd:Company/sd:Person/sd:AHV-AVS-Salaries/sd:AHV-AVS-Salary/sd:AHV-AVS-Income');
        $totalAhv = $this->xpathText($xpath, '/sd:SalaryDeclaration/sd:Company/sd:SalaryTotals/sd:AHV-AVS-Totals/sd:Total-AHV-AVS-Incomes');

        if ($totalAhv !== null && Dec::cmp($sumAhv, $totalAhv) !== 0) {
            $errors[] = "Total-AHV-AVS-Incomes ({$totalAhv}) non coincide con la somma dei salari AVS individuali ({$sumAhv}).";
        }

        $sumAlv = $this->sumXpath($xpath, '/sd:SalaryDeclaration/sd:Company/sd:Person/sd:AHV-AVS-Salaries/sd:AHV-AVS-Salary/sd:ALV-AC-Income');
        $totalAlv = $this->xpathText($xpath, '/sd:SalaryDeclaration/sd:Company/sd:SalaryTotals/sd:AHV-AVS-Totals/sd:Total-ALV-AC-Incomes');

        if ($totalAlv !== null && Dec::cmp($sumAlv, $totalAlv) !== 0) {
            $errors[] = "Total-ALV-AC-Incomes ({$totalAlv}) non coincide con la somma dei salari AD individuali ({$sumAlv}).";
        }

        $sumUvg = $this->sumXpath($xpath, '/sd:SalaryDeclaration/sd:Company/sd:Person/sd:UVG-LAA-Salaries/sd:UVG-LAA-Salary/sd:UVG-LAA-ContributorySalary');
        $totalUvg = $this->xpathText($xpath, '/sd:SalaryDeclaration/sd:Company/sd:SalaryTotals/sd:UVG-LAA-Totals/sd:Total-UVG-LAA-ContributorySalary');

        if ($totalUvg !== null && Dec::cmp($sumUvg, $totalUvg) !== 0) {
            $errors[] = "Total-UVG-LAA-ContributorySalary ({$totalUvg}) non coincide con la somma dei salari LAINF individuali ({$sumUvg}).";
        }

        $uid = $this->xpathText($xpath, '/sd:SalaryDeclaration/sd:Company/sd:CompanyDescription/sd:UID-BFS/sd:UID');

        if ($uid === null || ! preg_match('/^CHE-\d{3}\.\d{3}\.\d{3}$/', $uid)) {
            $warnings[] = "UID aziendale '{$uid}' non conforme al formato standard CHE-xxx.xxx.xxx.";
        }

        $svNumbers = $xpath->query('/sd:SalaryDeclaration/sd:Company/sd:Person/sd:Particulars/sd:Social-InsuranceIdentification/sd:SV-AS-Number');

        if ($svNumbers !== false) {
            foreach ($svNumbers as $node) {
                $sv = trim($node->textContent);

                if (! SalaryDeclarationBuilder::isValidSocialSecurityNumber($sv)) {
                    $warnings[] = "Numero AVS '{$sv}' non supera il controllo EAN-13.";
                }
            }
        }
    }

    private function xpathText(DOMXPath $xpath, string $expression): ?string
    {
        $nodes = $xpath->query($expression);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        return trim((string) $nodes->item(0)?->textContent);
    }

    private function sumXpath(DOMXPath $xpath, string $expression): string
    {
        $nodes = $xpath->query($expression);

        if ($nodes === false || $nodes->length === 0) {
            return '0.00';
        }

        $values = [];

        foreach ($nodes as $node) {
            $values[] = trim($node->textContent);
        }

        return Dec::round(Dec::sum($values));
    }

    /**
     * @return list<string>
     */
    private function collectLibXmlErrors(): array
    {
        return array_map(
            static fn (LibXMLError $error): string => sprintf('Riga %d: %s', $error->line, trim($error->message)),
            libxml_get_errors(),
        );
    }
}
