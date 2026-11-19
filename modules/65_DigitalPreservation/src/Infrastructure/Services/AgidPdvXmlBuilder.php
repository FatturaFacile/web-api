<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Services;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationException;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatchItem;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationConfiguration;

/**
 * Builds the deterministic, schema-validated PdV.xml index of a submission package.
 *
 * The structure follows the reference template of the preservation specification (namespace
 * `urn:agid:conservazione:pdv:v1.2`). It is the application's own profile inspired by the AgID rules and SInCRO, not a
 * certified UNI 11386 file: the conservator may require a mapping. The same inputs always produce the same bytes.
 */
final class AgidPdvXmlBuilder
{
    public const string Namespace = 'urn:agid:conservazione:pdv:v1.2';

    public const string Version = '1.2';

    /**
     * @param  Collection<int, PreservationBatchItem>  $items
     * @param  array{name: string, tax_code: ?string, vat: ?string}  $producer
     *
     * @throws PreservationException when the generated file does not validate against the schema
     */
    public function build(PreservationBatch $batch, Collection $items, PreservationConfiguration $configuration, array $producer, Carbon $generatedAt): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $dom->preserveWhiteSpace = false;

        $root = $dom->createElementNS(self::Namespace, 'PacchettoDiVersamento');
        $dom->appendChild($root);
        $root->setAttribute('DataGenerazione', $generatedAt->toIso8601String());
        $root->setAttribute('Giurisdizione', $batch->jurisdiction->value);
        $root->setAttribute('IdPdV', 'PDV-'.$batch->batch_number);
        $root->setAttribute('Versione', self::Version);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        $header = $this->child($dom, $root, 'Intestazione');

        $producerNode = $this->child($dom, $header, 'SoggettoProduttore');
        $this->text($dom, $producerNode, 'Denominazione', $producer['name']);
        $this->text($dom, $producerNode, 'CodiceFiscale', (string) $producer['tax_code']);
        $this->text($dom, $producerNode, 'PartitaIVA_UID', (string) $producer['vat']);
        $this->text($dom, $producerNode, 'TenantUUID', $batch->tenant_id);

        $officer = $this->child($dom, $header, 'ResponsabileConservazione');
        $this->text($dom, $officer, 'NomeCognome', (string) $configuration->preservation_officer_name);
        $this->text($dom, $officer, 'CodiceFiscale', (string) $configuration->preservation_officer_tax_code);
        $this->text($dom, $officer, 'Qualifica', 'Responsabile della Conservazione');

        $system = $this->child($dom, $header, 'ConfigurazioneSistema');
        $this->text($dom, $system, 'SistemaVersante', 'FatturaFacile ERP');
        $this->text($dom, $system, 'ConservatoreDestinazione', $configuration->provider->pdvCode());
        $period = $this->child($dom, $system, 'PeriodoRiferimento');
        $period->setAttribute('Al', $batch->period_to->toDateString());
        $period->setAttribute('Dal', $batch->period_from->toDateString());
        $this->text($dom, $system, 'ConservazioneMinimaAnni', (string) max($configuration->default_retention_years, (int) config('digital_preservation.minimum_retention_years')));
        $this->text($dom, $system, 'DataScadenzaVincolo', (string) $batch->retention_until?->toDateString());

        $chain = $this->child($dom, $header, 'CatenaIntegrita');
        $this->text($dom, $chain, 'AlgoritmoHash', 'SHA-256');
        $this->text($dom, $chain, 'RadiceMerkleBatch', (string) $batch->merkle_root_sha256);
        $this->text($dom, $chain, 'ImprontaBatchPrecedente', $batch->previous_batch_sha256 ?? str_repeat('0', 64));

        $index = $this->child($dom, $root, 'IndiceDocumenti');
        $index->setAttribute('TotaleDocumenti', (string) $items->count());

        foreach ($items->sortBy('sequence') as $item) {
            $this->document($dom, $index, $item, $generatedAt);
        }

        $xml = (string) $dom->saveXML();

        $this->assertValid($xml);

        return $xml;
    }

    /**
     * Validate a PdV.xml against the embedded schema.
     *
     * @throws PreservationException when it does not validate
     */
    public function assertValid(string $xml): void
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;

        try {
            $loaded = $dom->loadXML($xml);
            $valid = $loaded && $dom->schemaValidateSource(self::schema());
            $errors = array_map(static fn ($error): string => trim($error->message), libxml_get_errors());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $valid) {
            throw new PreservationException('The PdV.xml does not validate: '.implode('; ', array_slice($errors, 0, 3)));
        }
    }

    private function document(DOMDocument $dom, DOMElement $index, PreservationBatchItem $item, Carbon $generatedAt): void
    {
        $meta = $item->metadata_json ?? [];
        $digital = ($meta['digital_native'] ?? true) === true;

        $document = $this->child($dom, $index, 'Documento');
        $document->setAttribute('Informatico', $digital ? 'true' : 'false');
        $document->setAttribute('TipologiaDocumentale', (string) ($meta['agid_type'] ?? $item->document_type));

        $this->text($dom, $document, 'IdDoc', (string) ($meta['id_doc'] ?? $item->document_uuid));
        $this->text($dom, $document, 'ChiaveArchivio', $item->archive_path);
        $this->text($dom, $document, 'NomeFileOriginale', $item->original_filename);
        $this->text($dom, $document, 'Oggetto', (string) ($meta['subject'] ?? $item->original_filename));
        $hash = $this->text($dom, $document, 'ImprontaCrittografica', $item->sha256_hash);
        $hash->setAttribute('Algoritmo', 'SHA-256');

        $format = $this->child($dom, $document, 'Formato');
        $this->text($dom, $format, 'MimeType', $item->mime_type);
        $this->text($dom, $format, 'Estensione', (string) ($meta['extension'] ?? pathinfo($item->archived_filename, PATHINFO_EXTENSION)));
        $this->text($dom, $format, 'StandardConformita', (string) ($item->pdfa_conformance_level ?? $meta['standard'] ?? 'OPEN_FORMAT'));
        $this->text($dom, $format, 'IdoneoLungoTermine', 'true');

        if (isset($meta['sender']) || isset($meta['recipient'])) {
            $subjects = $this->child($dom, $document, 'Soggetti');
            foreach (['Mittente' => 'sender', 'Destinatario' => 'recipient'] as $tag => $key) {
                if (isset($meta[$key])) {
                    $node = $this->child($dom, $subjects, $tag);
                    $node->setAttribute('Denominazione', (string) ($meta[$key]['name'] ?? ''));
                    $node->setAttribute('IdentificativoFiscale', (string) ($meta[$key]['tax_id'] ?? ''));
                }
            }
        }

        if (isset($meta['fiscal'])) {
            $fiscal = $this->child($dom, $document, 'MetadatiFiscali');
            $this->text($dom, $fiscal, 'NumeroDocumento', (string) ($item->document_number ?? ''));
            $this->text($dom, $fiscal, 'DataDocumento', (string) $item->document_date?->toDateString());

            if (($meta['fiscal']['sdi_identifier'] ?? null) !== null) {
                $this->text($dom, $fiscal, 'IdentificativoSDI', (string) $meta['fiscal']['sdi_identifier']);
            }

            if (($meta['fiscal']['total_amount'] ?? null) !== null) {
                $amount = $this->text($dom, $fiscal, 'ImportoTotale', (string) $meta['fiscal']['total_amount']);
                $amount->setAttribute('Valuta', (string) ($meta['fiscal']['currency'] ?? 'EUR'));
            }
        }

        if (isset($meta['signature'])) {
            $signature = $this->child($dom, $document, 'VerificaFirmaDigitale');
            $this->text($dom, $signature, 'Firmato', ($meta['signature']['signed'] ?? false) ? 'true' : 'false');
            $this->text($dom, $signature, 'FormatoFirma', (string) ($meta['signature']['format'] ?? 'NONE'));
            $this->text($dom, $signature, 'EsitoVerifica', (string) ($meta['signature']['outcome'] ?? 'NOT_VERIFIED'));
            $this->text($dom, $signature, 'DataVerifica', $generatedAt->toIso8601String());
        }

        if (! empty($meta['related'])) {
            $related = $this->child($dom, $document, 'DocumentiCorrelati');
            foreach ($meta['related'] as $reference) {
                $node = $this->text($dom, $related, 'RiferimentoIdDoc', (string) $reference['id_doc']);
                $node->setAttribute('TipoRelazione', (string) $reference['relation']);
            }
        }

        if (! $digital) {
            $attestation = $this->child($dom, $document, 'AttestazioneConformita');
            $this->text($dom, $attestation, 'TipoCopia', 'COPIA_PER_IMMAGINE_SU_SUPPORTO_INFORMATICO');
            $this->text($dom, $attestation, 'RiferimentoNormativo', 'Art. 22 CAD / Art. 7 GeBüV');
        }

        $times = $this->child($dom, $document, 'RiferimentiTemporali');
        $this->text($dom, $times, 'DataFormazione', (string) ($meta['formation_date'] ?? $item->created_at?->toIso8601String()));
        $this->text($dom, $times, 'DataChiusura', $generatedAt->toIso8601String());
    }

    private function child(DOMDocument $dom, DOMElement $parent, string $name): DOMElement
    {
        $node = $dom->createElementNS(self::Namespace, $name);
        $parent->appendChild($node);

        return $node;
    }

    private function text(DOMDocument $dom, DOMElement $parent, string $name, string $value): DOMElement
    {
        $node = $this->child($dom, $parent, $name);
        $node->appendChild($dom->createTextNode($value));

        return $node;
    }

    /**
     * The XML Schema of the PdV profile.
     */
    public static function schema(): string
    {
        $ns = self::Namespace;

        return <<<XSD
<?xml version="1.0" encoding="UTF-8"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns="{$ns}" targetNamespace="{$ns}" elementFormDefault="qualified">
  <xs:simpleType name="Sha256"><xs:restriction base="xs:string"><xs:pattern value="[0-9a-f]{64}"/></xs:restriction></xs:simpleType>
  <xs:simpleType name="Bool"><xs:restriction base="xs:string"><xs:enumeration value="true"/><xs:enumeration value="false"/></xs:restriction></xs:simpleType>
  <xs:element name="PacchettoDiVersamento">
    <xs:complexType>
      <xs:sequence>
        <xs:element name="Intestazione">
          <xs:complexType>
            <xs:sequence>
              <xs:element name="SoggettoProduttore"><xs:complexType><xs:sequence>
                <xs:element name="Denominazione" type="xs:string"/>
                <xs:element name="CodiceFiscale" type="xs:string"/>
                <xs:element name="PartitaIVA_UID" type="xs:string"/>
                <xs:element name="TenantUUID" type="xs:string"/>
              </xs:sequence></xs:complexType></xs:element>
              <xs:element name="ResponsabileConservazione"><xs:complexType><xs:sequence>
                <xs:element name="NomeCognome" type="xs:string"/>
                <xs:element name="CodiceFiscale" type="xs:string"/>
                <xs:element name="Qualifica" type="xs:string"/>
              </xs:sequence></xs:complexType></xs:element>
              <xs:element name="ConfigurazioneSistema"><xs:complexType><xs:sequence>
                <xs:element name="SistemaVersante" type="xs:string"/>
                <xs:element name="ConservatoreDestinazione" type="xs:string"/>
                <xs:element name="PeriodoRiferimento"><xs:complexType>
                  <xs:attribute name="Al" type="xs:date" use="required"/><xs:attribute name="Dal" type="xs:date" use="required"/>
                </xs:complexType></xs:element>
                <xs:element name="ConservazioneMinimaAnni"><xs:simpleType><xs:restriction base="xs:integer"><xs:minInclusive value="10"/></xs:restriction></xs:simpleType></xs:element>
                <xs:element name="DataScadenzaVincolo" type="xs:date"/>
              </xs:sequence></xs:complexType></xs:element>
              <xs:element name="CatenaIntegrita"><xs:complexType><xs:sequence>
                <xs:element name="AlgoritmoHash"><xs:simpleType><xs:restriction base="xs:string"><xs:enumeration value="SHA-256"/></xs:restriction></xs:simpleType></xs:element>
                <xs:element name="RadiceMerkleBatch" type="Sha256"/>
                <xs:element name="ImprontaBatchPrecedente" type="Sha256"/>
              </xs:sequence></xs:complexType></xs:element>
            </xs:sequence>
          </xs:complexType>
        </xs:element>
        <xs:element name="IndiceDocumenti">
          <xs:complexType>
            <xs:sequence>
              <xs:element name="Documento" minOccurs="0" maxOccurs="unbounded">
                <xs:complexType>
                  <xs:sequence>
                    <xs:element name="IdDoc" type="xs:string"/>
                    <xs:element name="ChiaveArchivio" type="xs:string"/>
                    <xs:element name="NomeFileOriginale" type="xs:string"/>
                    <xs:element name="Oggetto" type="xs:string"/>
                    <xs:element name="ImprontaCrittografica"><xs:complexType><xs:simpleContent><xs:extension base="Sha256">
                      <xs:attribute name="Algoritmo" use="required"><xs:simpleType><xs:restriction base="xs:string"><xs:enumeration value="SHA-256"/></xs:restriction></xs:simpleType></xs:attribute>
                    </xs:extension></xs:simpleContent></xs:complexType></xs:element>
                    <xs:element name="Formato"><xs:complexType><xs:sequence>
                      <xs:element name="MimeType" type="xs:string"/>
                      <xs:element name="Estensione" type="xs:string"/>
                      <xs:element name="StandardConformita" type="xs:string"/>
                      <xs:element name="IdoneoLungoTermine" type="Bool"/>
                    </xs:sequence></xs:complexType></xs:element>
                    <xs:element name="Soggetti" minOccurs="0"><xs:complexType><xs:sequence>
                      <xs:element name="Mittente" minOccurs="0"><xs:complexType><xs:attribute name="Denominazione" type="xs:string"/><xs:attribute name="IdentificativoFiscale" type="xs:string"/></xs:complexType></xs:element>
                      <xs:element name="Destinatario" minOccurs="0"><xs:complexType><xs:attribute name="Denominazione" type="xs:string"/><xs:attribute name="IdentificativoFiscale" type="xs:string"/></xs:complexType></xs:element>
                    </xs:sequence></xs:complexType></xs:element>
                    <xs:element name="MetadatiFiscali" minOccurs="0"><xs:complexType><xs:sequence>
                      <xs:element name="NumeroDocumento" type="xs:string"/>
                      <xs:element name="DataDocumento" type="xs:string"/>
                      <xs:element name="IdentificativoSDI" type="xs:string" minOccurs="0"/>
                      <xs:element name="ImportoTotale" minOccurs="0"><xs:complexType><xs:simpleContent><xs:extension base="xs:decimal"><xs:attribute name="Valuta" type="xs:string" use="required"/></xs:extension></xs:simpleContent></xs:complexType></xs:element>
                    </xs:sequence></xs:complexType></xs:element>
                    <xs:element name="VerificaFirmaDigitale" minOccurs="0"><xs:complexType><xs:sequence>
                      <xs:element name="Firmato" type="Bool"/>
                      <xs:element name="FormatoFirma" type="xs:string"/>
                      <xs:element name="EsitoVerifica" type="xs:string"/>
                      <xs:element name="DataVerifica" type="xs:dateTime"/>
                    </xs:sequence></xs:complexType></xs:element>
                    <xs:element name="DocumentiCorrelati" minOccurs="0"><xs:complexType><xs:sequence>
                      <xs:element name="RiferimentoIdDoc" maxOccurs="unbounded"><xs:complexType><xs:simpleContent><xs:extension base="xs:string"><xs:attribute name="TipoRelazione" type="xs:string" use="required"/></xs:extension></xs:simpleContent></xs:complexType></xs:element>
                    </xs:sequence></xs:complexType></xs:element>
                    <xs:element name="AttestazioneConformita" minOccurs="0"><xs:complexType><xs:sequence>
                      <xs:element name="TipoCopia" type="xs:string"/>
                      <xs:element name="RiferimentoNormativo" type="xs:string"/>
                    </xs:sequence></xs:complexType></xs:element>
                    <xs:element name="RiferimentiTemporali"><xs:complexType><xs:sequence>
                      <xs:element name="DataFormazione" type="xs:dateTime"/>
                      <xs:element name="DataChiusura" type="xs:dateTime"/>
                    </xs:sequence></xs:complexType></xs:element>
                  </xs:sequence>
                  <xs:attribute name="Informatico" type="Bool" use="required"/>
                  <xs:attribute name="TipologiaDocumentale" type="xs:string" use="required"/>
                </xs:complexType>
              </xs:element>
            </xs:sequence>
            <xs:attribute name="TotaleDocumenti" type="xs:nonNegativeInteger" use="required"/>
          </xs:complexType>
        </xs:element>
      </xs:sequence>
      <xs:attribute name="DataGenerazione" type="xs:dateTime" use="required"/>
      <xs:attribute name="Giurisdizione" use="required"><xs:simpleType><xs:restriction base="xs:string"><xs:enumeration value="IT"/><xs:enumeration value="CH"/><xs:enumeration value="DUAL"/></xs:restriction></xs:simpleType></xs:attribute>
      <xs:attribute name="IdPdV" type="xs:string" use="required"/>
      <xs:attribute name="Versione" type="xs:string" use="required"/>
    </xs:complexType>
  </xs:element>
</xs:schema>
XSD;
    }
}
