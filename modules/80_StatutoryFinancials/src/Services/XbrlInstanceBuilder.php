<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use App\Models\Tenant;
use DOMDocument;
use DOMElement;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\StatutoryFinancials\Domain\Statement\LineCatalog;
use Modules\StatutoryFinancials\Domain\Statement\StatementBundle;
use Modules\StatutoryFinancials\Domain\Strategy\XbrlProfile;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Support\Money;

/**
 * Builds the XBRL instance document of a dossier: schema reference to the taxonomy entry point, the contexts (instant and duration of
 * the year and of the comparative year), the monetary and pure units and one fact per statement line, with the precision (decimals)
 * of the rounding mode. For Italy the elements follow the XBRL Italia / InfoCamere (TEBENI) naming of the configured taxonomy
 * release; for Switzerland the structured package namespace is used.
 */
final class XbrlInstanceBuilder
{
    public const string XbrliNs = 'http://www.xbrl.org/2003/instance';

    public const string LinkNs = 'http://www.xbrl.org/2003/linkbase';

    public const string XlinkNs = 'http://www.w3.org/1999/xlink';

    public const string Iso4217Ns = 'http://www.xbrl.org/2003/iso4217';

    /**
     * @param  array<string, mixed>|null  $cashFlow  Result of CashFlowStatementBuilder::build().
     * @param  array<string, mixed>|null  $notes  Quantitative tables of the notes.
     */
    public function build(StatutoryDossier $dossier, StatementBundle $bundle, XbrlProfile $profile, ?array $cashFlow, ?LineCatalog $cashFlowCatalog, ?array $notes): string
    {
        $year = FiscalYear::query()->findOrFail($dossier->fiscal_year_id);
        $prior = $dossier->comparative_prior_year_id !== null ? FiscalYear::query()->find($dossier->comparative_prior_year_id) : null;
        $tenant = Tenant::query()->find($dossier->tenant_id);
        $decimals = $bundle->scale === 0 ? '0' : '2';

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS(self::XbrliNs, 'xbrli:xbrl');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:link', self::LinkNs);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xlink', self::XlinkNs);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:iso4217', self::Iso4217Ns);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:'.$profile->prefix, $profile->namespace);
        $dom->appendChild($root);

        $ref = $dom->createElementNS(self::LinkNs, 'link:schemaRef');
        $ref->setAttributeNS(self::XlinkNs, 'xlink:type', 'simple');
        $ref->setAttributeNS(self::XlinkNs, 'xlink:href', $profile->entryPoint);
        $root->appendChild($ref);

        $identifier = (string) ($tenant->vat_number ?? $tenant->fiscal_code ?? $tenant->uid_number ?? $dossier->tenant_id);
        $contexts = ['i_current' => ['instant', null, $year->end_date->toDateString()], 'd_current' => ['duration', $year->start_date->toDateString(), $year->end_date->toDateString()]];

        if ($prior !== null) {
            $contexts['i_prior'] = ['instant', null, $prior->end_date->toDateString()];
            $contexts['d_prior'] = ['duration', $prior->start_date->toDateString(), $prior->end_date->toDateString()];
        }

        foreach ($contexts as $id => [$type, $start, $end]) {
            $root->appendChild($this->context($dom, $id, $type, $start, $end, $profile->entityScheme, $identifier));
        }

        foreach (['u_money' => 'iso4217:'.$profile->unit, 'u_pure' => 'xbrli:pure'] as $id => $measure) {
            $unit = $dom->createElementNS(self::XbrliNs, 'xbrli:unit');
            $unit->setAttribute('id', $id);
            $unit->appendChild($dom->createElementNS(self::XbrliNs, 'xbrli:measure', $measure));
            $root->appendChild($unit);
        }

        $this->statementFacts($dom, $root, $profile, $bundle->balanceSheet, $bundle->quadrature->balanceSheetTotals, $bundle->comparativeBalanceSheet, 'i', $decimals, false);
        $this->statementFacts($dom, $root, $profile, $bundle->incomeStatement, $bundle->quadrature->incomeStatementTotals, $bundle->comparativeIncomeStatement, 'd', $decimals, false);

        if ($cashFlow !== null && $cashFlowCatalog !== null) {
            $this->statementFacts($dom, $root, $profile, $cashFlowCatalog, $cashFlow['totals'], null, 'd', $decimals, true);
        }

        if ($notes !== null) {
            $this->noteFacts($dom, $root, $profile, $notes, $decimals);
        }

        return (string) $dom->saveXML();
    }

    /**
     * @param  array<string, string>  $amounts
     * @param  array<string, string>|null  $comparative
     */
    private function statementFacts(DOMDocument $dom, DOMElement $root, XbrlProfile $profile, LineCatalog $catalog, array $amounts, ?array $comparative, string $kind, string $decimals, bool $cashFlow): void
    {
        foreach ($catalog->lines() as $code => $line) {
            if ($line->xbrlElement === null) {
                continue;
            }

            $element = $this->elementName($profile, $line->xbrlElement, $cashFlow);
            $isTotal = $line->formula !== null || ! $catalog->isLeaf($code);

            foreach ([['current', $amounts[$code] ?? null], ['prior', $comparative[$code] ?? null]] as [$period, $amount]) {
                if ($amount === null || ($period === 'prior' && $comparative === null)) {
                    continue;
                }

                if (! $isTotal && Money::isZero($amount) && ! $line->isRounding) {
                    continue;
                }

                $fact = $dom->createElementNS($profile->namespace, $element, $this->number($amount, $decimals));
                $fact->setAttribute('contextRef', $kind.'_'.$period);
                $fact->setAttribute('unitRef', 'u_money');
                $fact->setAttribute('decimals', $decimals);
                $root->appendChild($fact);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $notes
     */
    private function noteFacts(DOMDocument $dom, DOMElement $root, XbrlProfile $profile, array $notes, string $decimals): void
    {
        $facts = [];

        if (isset($notes['workforce']['total_headcount'])) {
            $facts[] = ['NumberOfEmployeesAverage', $notes['workforce']['total_headcount'], 'u_pure', '2', 'd_current'];
        }

        if (isset($notes['fixed_assets']['totals']['net_book_value'])) {
            $facts[] = ['FixedAssetsNetBookValueNotes', $notes['fixed_assets']['totals']['net_book_value'], 'u_money', $decimals, 'i_current'];
        }

        foreach (['receivables' => 'ReceivablesBeyondFiveYears', 'payables' => 'PayablesBeyondFiveYears'] as $table => $name) {
            if (isset($notes['maturity'][$table]['beyond_5y'])) {
                $facts[] = [$name, $notes['maturity'][$table]['beyond_5y'], 'u_money', $decimals, 'i_current'];
            }
        }

        foreach ($notes['compensation']['rows'] ?? [] as $row) {
            $facts[] = [($row['role'] === 'directors' ? 'DirectorsCompensation' : 'StatutoryAuditorsCompensation'), $row['fees'], 'u_money', $decimals, 'd_current'];
        }

        foreach ($facts as [$name, $value, $unit, $precision, $context]) {
            $fact = $dom->createElementNS($profile->namespace, $profile->prefix.':'.$name, $this->number((string) $value, $precision));
            $fact->setAttribute('contextRef', $context);
            $fact->setAttribute('unitRef', $unit);
            $fact->setAttribute('decimals', $precision);
            $root->appendChild($fact);
        }
    }

    private function context(DOMDocument $dom, string $id, string $type, ?string $start, string $end, string $scheme, string $identifier): DOMElement
    {
        $context = $dom->createElementNS(self::XbrliNs, 'xbrli:context');
        $context->setAttribute('id', $id);
        $entity = $dom->createElementNS(self::XbrliNs, 'xbrli:entity');
        $entity->appendChild($dom->createElementNS(self::XbrliNs, 'xbrli:identifier', htmlspecialchars($identifier, ENT_XML1)))->setAttribute('scheme', $scheme);
        $context->appendChild($entity);
        $period = $dom->createElementNS(self::XbrliNs, 'xbrli:period');

        if ($type === 'instant') {
            $period->appendChild($dom->createElementNS(self::XbrliNs, 'xbrli:instant', $end));
        } else {
            $period->appendChild($dom->createElementNS(self::XbrliNs, 'xbrli:startDate', (string) $start));
            $period->appendChild($dom->createElementNS(self::XbrliNs, 'xbrli:endDate', $end));
        }

        $context->appendChild($period);

        return $context;
    }

    private function elementName(XbrlProfile $profile, string $taxonomyElement, bool $cashFlow): string
    {
        $local = str_contains($taxonomyElement, ':') ? substr($taxonomyElement, (int) strpos($taxonomyElement, ':') + 1) : $taxonomyElement;

        return $profile->prefix.':'.($cashFlow ? 'CF' : '').$local;
    }

    private function number(string $amount, string $decimals): string
    {
        return Money::round($amount, (int) $decimals);
    }
}
