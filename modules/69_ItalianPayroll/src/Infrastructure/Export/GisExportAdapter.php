<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Export;

use DOMDocument;
use Modules\ItalianPayroll\Domain\Enums\ConsultantFormat;

/**
 * Wolters Kluwer / GIS Ranocchi XML layout: <Presenze> with one <Movimento> per attendance line.
 */
final class GisExportAdapter implements ConsulenteDelLavoroExportAdapter
{
    public function format(): ConsultantFormat
    {
        return ConsultantFormat::Gis;
    }

    public function render(int $year, int $month, string $companyCode, array $rows): ExportFile
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->appendChild($document->createElement('Presenze'));
        $root->setAttribute('azienda', $companyCode);
        $root->setAttribute('anno', (string) $year);
        $root->setAttribute('mese', sprintf('%02d', $month));

        foreach ($rows as $row) {
            $movement = $root->appendChild($document->createElement('Movimento'));
            $fields = [
                'Matricola' => $row->matricola, 'Voce' => $row->voiceCode, 'Quantita' => $row->quantity, 'UnitaMisura' => $row->unit,
                'BuoniPasto' => (string) $row->mealVouchers, 'Rimborso' => $row->reimbursement, 'Fringe' => $row->fringeBenefit,
            ];

            if ($row->inpsProtocol !== null && $row->inpsProtocol !== '') {
                $fields['ProtocolloInps'] = $row->inpsProtocol;
            }

            foreach ($fields as $name => $value) {
                $movement->appendChild($document->createElement($name))->appendChild($document->createTextNode($value));
            }
        }

        return new ExportFile(sprintf('GIS_%s_%04d%02d.xml', $companyCode, $year, $month), 'application/xml', (string) $document->saveXML());
    }
}
